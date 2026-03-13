<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DnsManager extends Component
{
    use AuthorizesRequests;

    public Server $server;

    // Hostname
    public string $hostname = '';
    public string $currentHostname = '';

    // /etc/hosts
    public array $hostsEntries = [];
    public string $newHostIp = '';
    public string $newHostName = '';

    // DNS Resolvers
    public array $dnsResolvers = [];
    public string $newDnsResolver = '';
    public string $resolvConfContent = '';

    // Search domains
    public array $searchDomains = [];
    public string $newSearchDomain = '';

    // DNS Lookup
    public string $lookupDomain = '';
    public string $lookupType = 'A';
    public string $lookupServer = '';
    public string $lookupResult = '';

    // DNS Zone Records (dig-based query for any domain)
    public string $zoneDomain = '';
    public array $zoneRecords = [];
    public string $newRecordName = '';
    public string $newRecordType = 'A';
    public string $newRecordValue = '';
    public int $newRecordTtl = 3600;
    public int $newRecordPriority = 10;

    // resolv.conf raw edit
    public string $resolvConfEdit = '';
    public bool $rawEditMode = false;

    // DNS Propagation Check
    public string $propagationDomain = '';
    public string $propagationType = 'A';
    public array $propagationResults = [];

    public bool $isLoading = true;

    // Available record types for forms
    public array $recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR', 'SOA'];

    // Public DNS servers for propagation check
    public array $publicDnsServers = [
        ['name' => 'Google', 'ip' => '8.8.8.8'],
        ['name' => 'Cloudflare', 'ip' => '1.1.1.1'],
        ['name' => 'Quad9', 'ip' => '9.9.9.9'],
        ['name' => 'OpenDNS', 'ip' => '208.67.222.222'],
        ['name' => 'Google 2', 'ip' => '8.8.4.4'],
        ['name' => 'Cloudflare 2', 'ip' => '1.0.0.1'],
    ];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->loadDnsData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadDnsData()
    {
        try {
            $this->isLoading = true;

            // Get hostname
            $this->currentHostname = trim(instant_remote_process(['hostname'], $this->server, false));
            $this->hostname = $this->currentHostname;

            // Get /etc/hosts
            $hostsContent = trim(instant_remote_process(['cat /etc/hosts'], $this->server, false));
            $this->parseHostsFile($hostsContent);

            // Get /etc/resolv.conf
            $this->resolvConfContent = trim(instant_remote_process(['cat /etc/resolv.conf'], $this->server, false));
            $this->resolvConfEdit = $this->resolvConfContent;
            $this->parseDnsResolvers($this->resolvConfContent);
            $this->parseSearchDomains($this->resolvConfContent);

            $this->isLoading = false;
        } catch (\Throwable $e) {
            $this->isLoading = false;
            return handleError($e, $this);
        }
    }

    private function parseHostsFile(string $content): void
    {
        $this->hostsEntries = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 2) {
                $ip = $parts[0];
                $hostnames = array_slice($parts, 1);
                $this->hostsEntries[] = [
                    'ip' => $ip,
                    'hostnames' => implode(' ', $hostnames),
                    'raw' => $line,
                ];
            }
        }
    }

    private function parseDnsResolvers(string $content): void
    {
        $this->dnsResolvers = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'nameserver')) {
                $parts = preg_split('/\s+/', $line);
                if (isset($parts[1])) {
                    $this->dnsResolvers[] = $parts[1];
                }
            }
        }
    }

    private function parseSearchDomains(string $content): void
    {
        $this->searchDomains = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'search ') || str_starts_with($line, 'domain ')) {
                $parts = preg_split('/\s+/', $line);
                array_shift($parts); // remove 'search' or 'domain'
                foreach ($parts as $domain) {
                    if (!empty($domain)) {
                        $this->searchDomains[] = $domain;
                    }
                }
            }
        }
    }

    // ─── HOSTNAME ──────────────────────────────────────────────

    public function updateHostname()
    {
        try {
            $this->authorize('update', $this->server);

            $hostname = trim($this->hostname);
            if (empty($hostname)) {
                $this->dispatch('error', 'Hostname cannot be empty.');
                return;
            }

            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $hostname)) {
                $this->dispatch('error', 'Invalid hostname format. Use only letters, numbers, hyphens and dots.');
                return;
            }

            instant_remote_process(["hostnamectl set-hostname " . escapeshellarg($hostname)], $this->server, false);
            $this->currentHostname = $hostname;
            $this->dispatch('success', 'Hostname updated to: ' . $hostname);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // ─── /etc/hosts ────────────────────────────────────────────

    public function addHostEntry()
    {
        try {
            $this->authorize('update', $this->server);

            $ip = trim($this->newHostIp);
            $name = trim($this->newHostName);

            if (empty($ip) || empty($name)) {
                $this->dispatch('error', 'Both IP address and hostname are required.');
                return;
            }

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->dispatch('error', 'Invalid IP address format.');
                return;
            }

            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $name)) {
                $this->dispatch('error', 'Invalid hostname format.');
                return;
            }

            $entry = $ip . "\t" . $name;
            instant_remote_process(["echo " . escapeshellarg($entry) . " >> /etc/hosts"], $this->server, false);

            $this->newHostIp = '';
            $this->newHostName = '';
            $this->loadDnsData();
            $this->dispatch('success', 'Host entry added successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeHostEntry(int $index)
    {
        try {
            $this->authorize('update', $this->server);

            if (!isset($this->hostsEntries[$index])) {
                $this->dispatch('error', 'Host entry not found.');
                return;
            }

            $entry = $this->hostsEntries[$index];
            $raw = $entry['raw'];

            $escapedLine = escapeshellarg($raw);
            instant_remote_process(["grep -vF " . $escapedLine . " /etc/hosts > /etc/hosts.tmp && mv /etc/hosts.tmp /etc/hosts"], $this->server, false);

            $this->loadDnsData();
            $this->dispatch('success', 'Host entry removed successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // ─── DNS RESOLVERS (NAMESERVERS) ───────────────────────────

    public function addDnsResolver()
    {
        try {
            $this->authorize('update', $this->server);

            $resolver = trim($this->newDnsResolver);

            if (empty($resolver)) {
                $this->dispatch('error', 'DNS resolver IP is required.');
                return;
            }

            if (!filter_var($resolver, FILTER_VALIDATE_IP)) {
                $this->dispatch('error', 'Invalid IP address format.');
                return;
            }

            if (in_array($resolver, $this->dnsResolvers)) {
                $this->dispatch('error', 'This DNS resolver already exists.');
                return;
            }

            instant_remote_process(["echo " . escapeshellarg("nameserver " . $resolver) . " >> /etc/resolv.conf"], $this->server, false);

            $this->newDnsResolver = '';
            $this->loadDnsData();
            $this->dispatch('success', 'DNS resolver added: ' . $resolver);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeDnsResolver(int $index)
    {
        try {
            $this->authorize('update', $this->server);

            if (!isset($this->dnsResolvers[$index])) {
                $this->dispatch('error', 'DNS resolver not found.');
                return;
            }

            if (count($this->dnsResolvers) <= 1) {
                $this->dispatch('error', 'Cannot remove the last DNS resolver. Add another one first.');
                return;
            }

            $resolver = $this->dnsResolvers[$index];
            $escapedLine = escapeshellarg("nameserver " . $resolver);
            instant_remote_process(["grep -vF " . $escapedLine . " /etc/resolv.conf > /etc/resolv.conf.tmp && mv /etc/resolv.conf.tmp /etc/resolv.conf"], $this->server, false);

            $this->loadDnsData();
            $this->dispatch('success', 'DNS resolver removed: ' . $resolver);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // ─── SEARCH DOMAINS ────────────────────────────────────────

    public function addSearchDomain()
    {
        try {
            $this->authorize('update', $this->server);

            $domain = trim($this->newSearchDomain);

            if (empty($domain)) {
                $this->dispatch('error', 'Search domain is required.');
                return;
            }

            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $domain)) {
                $this->dispatch('error', 'Invalid domain format.');
                return;
            }

            if (in_array($domain, $this->searchDomains)) {
                $this->dispatch('error', 'This search domain already exists.');
                return;
            }

            // Remove existing search line and rebuild it
            $newDomains = array_merge($this->searchDomains, [$domain]);
            $searchLine = 'search ' . implode(' ', $newDomains);

            $commands = [
                "grep -v '^search ' /etc/resolv.conf > /etc/resolv.conf.tmp",
                "echo " . escapeshellarg($searchLine) . " >> /etc/resolv.conf.tmp",
                "mv /etc/resolv.conf.tmp /etc/resolv.conf",
            ];
            instant_remote_process([implode(' && ', $commands)], $this->server, false);

            $this->newSearchDomain = '';
            $this->loadDnsData();
            $this->dispatch('success', 'Search domain added: ' . $domain);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeSearchDomain(int $index)
    {
        try {
            $this->authorize('update', $this->server);

            if (!isset($this->searchDomains[$index])) {
                $this->dispatch('error', 'Search domain not found.');
                return;
            }

            $remaining = $this->searchDomains;
            array_splice($remaining, $index, 1);

            $commands = ["grep -v '^search ' /etc/resolv.conf > /etc/resolv.conf.tmp"];
            if (!empty($remaining)) {
                $searchLine = 'search ' . implode(' ', $remaining);
                $commands[] = "echo " . escapeshellarg($searchLine) . " >> /etc/resolv.conf.tmp";
            }
            $commands[] = "mv /etc/resolv.conf.tmp /etc/resolv.conf";

            instant_remote_process([implode(' && ', $commands)], $this->server, false);

            $this->loadDnsData();
            $this->dispatch('success', 'Search domain removed.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // ─── RAW RESOLV.CONF EDIT ──────────────────────────────────

    public function toggleRawEdit()
    {
        $this->rawEditMode = !$this->rawEditMode;
        if ($this->rawEditMode) {
            $this->resolvConfEdit = $this->resolvConfContent;
        }
    }

    public function saveResolvConf()
    {
        try {
            $this->authorize('update', $this->server);

            $content = trim($this->resolvConfEdit);

            if (empty($content)) {
                $this->dispatch('error', 'resolv.conf content cannot be empty.');
                return;
            }

            // Validate it has at least one nameserver
            if (!preg_match('/nameserver\s+/', $content)) {
                $this->dispatch('error', 'resolv.conf must contain at least one nameserver entry.');
                return;
            }

            // Write via base64 to avoid shell escaping issues
            $encoded = base64_encode($content . "\n");
            instant_remote_process(["echo " . escapeshellarg($encoded) . " | base64 -d > /etc/resolv.conf"], $this->server, false);

            $this->rawEditMode = false;
            $this->loadDnsData();
            $this->dispatch('success', 'resolv.conf updated successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // ─── DNS LOOKUP ────────────────────────────────────────────

    public function dnsLookup()
    {
        try {
            $domain = trim($this->lookupDomain);

            if (empty($domain)) {
                $this->dispatch('error', 'Domain name is required.');
                return;
            }

            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $domain)) {
                $this->dispatch('error', 'Invalid domain format.');
                return;
            }

            $type = escapeshellarg($this->lookupType);
            $domainArg = escapeshellarg($domain);

            $cmd = "dig +noall +answer +authority +additional {$domainArg} {$type}";

            // Optional: query specific DNS server
            $server = trim($this->lookupServer);
            if (!empty($server)) {
                if (!filter_var($server, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9\-\.]+$/', $server)) {
                    $this->dispatch('error', 'Invalid DNS server address.');
                    return;
                }
                $cmd = "dig +noall +answer +authority +additional @" . escapeshellarg($server) . " {$domainArg} {$type}";
            }

            $this->lookupResult = trim(instant_remote_process([$cmd], $this->server, false));

            if (empty($this->lookupResult)) {
                $this->lookupResult = "No records found for {$domain} ({$this->lookupType})";
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // ─── DNS ZONE QUERY ────────────────────────────────────────

    public function queryZoneRecords()
    {
        try {
            $domain = trim($this->zoneDomain);

            if (empty($domain)) {
                $this->dispatch('error', 'Domain name is required.');
                return;
            }

            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $domain)) {
                $this->dispatch('error', 'Invalid domain format.');
                return;
            }

            $domainArg = escapeshellarg($domain);
            $this->zoneRecords = [];

            // Query common record types
            $types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA', 'CAA', 'SRV'];

            foreach ($types as $type) {
                $typeArg = escapeshellarg($type);
                $result = trim(instant_remote_process(
                    ["dig +noall +answer {$domainArg} {$typeArg} +ttlid 2>/dev/null"],
                    $this->server,
                    false
                ));

                if (!empty($result)) {
                    $lines = explode("\n", $result);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line) || str_starts_with($line, ';')) {
                            continue;
                        }
                        // Parse dig output: name TTL class type value
                        $parts = preg_split('/\s+/', $line, 5);
                        if (count($parts) >= 5) {
                            $record = [
                                'name' => rtrim($parts[0], '.'),
                                'ttl' => (int)$parts[1],
                                'class' => $parts[2],
                                'type' => $parts[3],
                                'value' => $parts[4],
                            ];

                            // Parse MX priority
                            if ($parts[3] === 'MX') {
                                $mxParts = explode(' ', $parts[4], 2);
                                $record['priority'] = (int)($mxParts[0] ?? 0);
                                $record['value'] = $mxParts[1] ?? $parts[4];
                            }

                            $this->zoneRecords[] = $record;
                        }
                    }
                }
            }

            if (empty($this->zoneRecords)) {
                $this->dispatch('error', 'No DNS records found for ' . $domain);
            } else {
                $this->dispatch('success', count($this->zoneRecords) . ' DNS records found for ' . $domain);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // ─── DNS PROPAGATION CHECK ─────────────────────────────────

    public function checkPropagation()
    {
        try {
            $domain = trim($this->propagationDomain);

            if (empty($domain)) {
                $this->dispatch('error', 'Domain name is required.');
                return;
            }

            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $domain)) {
                $this->dispatch('error', 'Invalid domain format.');
                return;
            }

            $this->propagationResults = [];
            $domainArg = escapeshellarg($domain);
            $typeArg = escapeshellarg($this->propagationType);

            foreach ($this->publicDnsServers as $dns) {
                $serverArg = escapeshellarg($dns['ip']);
                $result = trim(instant_remote_process(
                    ["dig +noall +answer +short @{$serverArg} {$domainArg} {$typeArg} +time=3 +tries=1 2>/dev/null || echo 'TIMEOUT'"],
                    $this->server,
                    false
                ));

                $this->propagationResults[] = [
                    'server' => $dns['name'],
                    'ip' => $dns['ip'],
                    'result' => empty($result) ? 'No record' : $result,
                    'status' => empty($result) || $result === 'TIMEOUT' ? 'error' : 'success',
                ];
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.dns-manager');
    }
}
