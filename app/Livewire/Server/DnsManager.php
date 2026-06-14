<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DnsManager extends Component
{
    use AuthorizesRequests;

    public Server $server;

    // ─── Hostname ──────────────────────────────────────────────
    public string $hostname = '';
    public string $currentHostname = '';

    // ─── /etc/hosts ────────────────────────────────────────────
    public array $hostsEntries = [];
    public string $newHostIp = '';
    public string $newHostName = '';

    // ─── DNS Resolvers ─────────────────────────────────────────
    public array $dnsResolvers = [];
    public string $newDnsResolver = '';
    public string $resolvConfContent = '';

    // ─── Search Domains ────────────────────────────────────────
    public array $searchDomains = [];
    public string $newSearchDomain = '';

    // ─── resolv.conf Raw Edit ──────────────────────────────────
    public string $resolvConfEdit = '';
    public bool $rawEditMode = false;

    // ─── DNS Server (BIND9) ────────────────────────────────────
    public bool $dnsServerInstalled = false;
    public string $dnsServerStatus = 'unknown';
    public string $dnsServerVersion = '';

    // ─── Zone Management ───────────────────────────────────────
    public array $zones = [];
    public string $newZoneDomain = '';
    public string $newZoneType = 'master';
    public string $newZoneMasterIp = '';
    public string $newZoneAdminEmail = 'admin';

    // ─── Selected Zone & Records ───────────────────────────────
    public string $selectedZone = '';
    public array $selectedZoneRecords = [];
    public string $selectedZoneSerial = '';
    public string $zoneFileContent = '';
    public bool $zoneRawEditMode = false;

    // ─── Add Record Form ───────────────────────────────────────
    public string $newRecordName = '';
    public string $newRecordType = 'A';
    public string $newRecordValue = '';
    public int $newRecordTtl = 3600;
    public int $newRecordPriority = 10;

    // ─── Edit Record ───────────────────────────────────────────
    public ?int $editingRecordIndex = null;
    public string $editRecordName = '';
    public string $editRecordType = 'A';
    public string $editRecordValue = '';
    public int $editRecordTtl = 3600;
    public int $editRecordPriority = 10;

    // ─── DNS Lookup ────────────────────────────────────────────
    public string $lookupDomain = '';
    public string $lookupType = 'A';
    public string $lookupServer = '';
    public string $lookupResult = '';

    // ─── DNS Zone Query (external dig) ─────────────────────────
    public string $zoneDomain = '';
    public array $zoneRecords = [];

    // ─── DNS Propagation Check ─────────────────────────────────
    public string $propagationDomain = '';
    public string $propagationType = 'A';
    public array $propagationResults = [];

    // ─── Shared ────────────────────────────────────────────────
    public bool $isLoading = true;
    public string $activeTab = 'server-config';

    public array $recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR', 'SOA'];

    public array $publicDnsServers = [
        ['name' => 'Google', 'ip' => '8.8.8.8'],
        ['name' => 'Google 2', 'ip' => '8.8.4.4'],
        ['name' => 'Cloudflare', 'ip' => '1.1.1.1'],
        ['name' => 'Cloudflare 2', 'ip' => '1.0.0.1'],
        ['name' => 'Quad9', 'ip' => '9.9.9.9'],
        ['name' => 'OpenDNS', 'ip' => '208.67.222.222'],
        ['name' => 'OpenDNS 2', 'ip' => '208.67.220.220'],
        ['name' => 'Comodo', 'ip' => '8.26.56.26'],
    ];

    /** @var string BIND config & zone path */
    private string $bindConfigPath = '/etc/bind/named.conf.local';
    private string $bindZonePath = '/etc/bind/zones';

    // ╔══════════════════════════════════════════════════════════╗
    // ║  LIFECYCLE                                              ║
    // ╚══════════════════════════════════════════════════════════╝

    public function mount(string $server_uuid): void
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->loadDnsData();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;

        if ($tab === 'dns-server' && $this->dnsServerStatus === 'unknown') {
            $this->detectDnsServer();
        }
    }

    public function loadDnsData(): void
    {
        try {
            $this->isLoading = true;

            $output = trim(instant_remote_process([
                'echo "===HOSTNAME===" && hostname',
                'echo "===HOSTS===" && cat /etc/hosts',
                'echo "===RESOLV===" && cat /etc/resolv.conf',
            ], $this->server, false));

            $sections = $this->parseSections($output, ['===HOSTNAME===', '===HOSTS===', '===RESOLV===']);

            $this->currentHostname = trim($sections['===HOSTNAME==='] ?? '');
            $this->hostname = $this->currentHostname;

            $this->parseHostsFile(trim($sections['===HOSTS==='] ?? ''));

            $resolvContent = trim($sections['===RESOLV==='] ?? '');
            $this->resolvConfContent = $resolvContent;
            $this->resolvConfEdit = $resolvContent;
            $this->parseDnsResolvers($resolvContent);
            $this->parseSearchDomains($resolvContent);

            $this->isLoading = false;
        } catch (\Throwable $e) {
            $this->isLoading = false;
            handleError($e, $this);
        }
    }

    /**
     * Parse output delimited by section markers.
     *
     * @return array<string, string>
     */
    private function parseSections(string $output, array $markers): array
    {
        $sections = [];
        $currentMarker = null;
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (in_array(trim($line), $markers, true)) {
                $currentMarker = trim($line);
                $sections[$currentMarker] = '';

                continue;
            }
            if ($currentMarker !== null) {
                $sections[$currentMarker] .= $line . "\n";
            }
        }

        return $sections;
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  PARSERS                                                ║
    // ╚══════════════════════════════════════════════════════════╝

    private function parseHostsFile(string $content): void
    {
        $this->hostsEntries = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 2) {
                $this->hostsEntries[] = [
                    'ip' => $parts[0],
                    'hostnames' => implode(' ', array_slice($parts, 1)),
                    'raw' => $line,
                ];
            }
        }
    }

    private function parseDnsResolvers(string $content): void
    {
        $this->dnsResolvers = [];

        foreach (explode("\n", $content) as $line) {
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

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'search ') || str_starts_with($line, 'domain ')) {
                $parts = preg_split('/\s+/', $line);
                array_shift($parts);
                foreach ($parts as $domain) {
                    if (! empty($domain)) {
                        $this->searchDomains[] = $domain;
                    }
                }
            }
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  HOSTNAME                                               ║
    // ╚══════════════════════════════════════════════════════════╝

    public function updateHostname(): void
    {
        try {
            $this->authorize('update', $this->server);

            $hostname = trim($this->hostname);
            if (empty($hostname)) {
                $this->dispatch('error', 'Hostname cannot be empty.');

                return;
            }

            if (! $this->isValidHostname($hostname)) {
                $this->dispatch('error', 'Invalid hostname format. Use only letters, numbers, hyphens and dots.');

                return;
            }

            instant_remote_process(['hostnamectl set-hostname ' . escapeshellarg($hostname)], $this->server, false);
            $this->currentHostname = $hostname;
            $this->dispatch('success', 'Hostname updated to: ' . $hostname);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  /etc/hosts                                             ║
    // ╚══════════════════════════════════════════════════════════╝

    public function addHostEntry(): void
    {
        try {
            $this->authorize('update', $this->server);

            $ip = trim($this->newHostIp);
            $name = trim($this->newHostName);

            if (empty($ip) || empty($name)) {
                $this->dispatch('error', 'Both IP address and hostname are required.');

                return;
            }

            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->dispatch('error', 'Invalid IP address format.');

                return;
            }

            if (! $this->isValidHostname($name)) {
                $this->dispatch('error', 'Invalid hostname format.');

                return;
            }

            $entry = $ip . "\t" . $name;
            instant_remote_process(['echo ' . escapeshellarg($entry) . ' >> /etc/hosts'], $this->server, false);

            $this->newHostIp = '';
            $this->newHostName = '';
            $this->loadDnsData();
            $this->dispatch('success', 'Host entry added successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeHostEntry(int $index): void
    {
        try {
            $this->authorize('update', $this->server);

            if (! isset($this->hostsEntries[$index])) {
                $this->dispatch('error', 'Host entry not found.');

                return;
            }

            $raw = $this->hostsEntries[$index]['raw'];
            instant_remote_process(
                ['sed -i ' . escapeshellarg('/' . preg_quote($raw, '/') . '/d') . ' /etc/hosts'],
                $this->server,
                false
            );

            $this->loadDnsData();
            $this->dispatch('success', 'Host entry removed successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  DNS RESOLVERS                                          ║
    // ╚══════════════════════════════════════════════════════════╝

    public function addDnsResolver(): void
    {
        try {
            $this->authorize('update', $this->server);

            $resolver = trim($this->newDnsResolver);
            if (empty($resolver)) {
                $this->dispatch('error', 'DNS resolver IP is required.');

                return;
            }

            if (! filter_var($resolver, FILTER_VALIDATE_IP)) {
                $this->dispatch('error', 'Invalid IP address format.');

                return;
            }

            if (in_array($resolver, $this->dnsResolvers)) {
                $this->dispatch('error', 'This DNS resolver already exists.');

                return;
            }

            instant_remote_process(['echo ' . escapeshellarg('nameserver ' . $resolver) . ' >> /etc/resolv.conf'], $this->server, false);

            $this->newDnsResolver = '';
            $this->loadDnsData();
            $this->dispatch('success', 'DNS resolver added: ' . $resolver);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeDnsResolver(int $index): void
    {
        try {
            $this->authorize('update', $this->server);

            if (! isset($this->dnsResolvers[$index])) {
                $this->dispatch('error', 'DNS resolver not found.');

                return;
            }

            if (count($this->dnsResolvers) <= 1) {
                $this->dispatch('error', 'Cannot remove the last DNS resolver. Add another one first.');

                return;
            }

            $resolver = $this->dnsResolvers[$index];
            $escapedLine = escapeshellarg('nameserver ' . $resolver);
            instant_remote_process(['grep -vF ' . $escapedLine . ' /etc/resolv.conf > /etc/resolv.conf.tmp && mv /etc/resolv.conf.tmp /etc/resolv.conf'], $this->server, false);

            $this->loadDnsData();
            $this->dispatch('success', 'DNS resolver removed: ' . $resolver);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  SEARCH DOMAINS                                         ║
    // ╚══════════════════════════════════════════════════════════╝

    public function addSearchDomain(): void
    {
        try {
            $this->authorize('update', $this->server);

            $domain = trim($this->newSearchDomain);
            if (empty($domain)) {
                $this->dispatch('error', 'Search domain is required.');

                return;
            }

            if (! $this->isValidDomain($domain)) {
                $this->dispatch('error', 'Invalid domain format.');

                return;
            }

            if (in_array($domain, $this->searchDomains)) {
                $this->dispatch('error', 'This search domain already exists.');

                return;
            }

            $newDomains = array_merge($this->searchDomains, [$domain]);
            $searchLine = 'search ' . implode(' ', $newDomains);

            instant_remote_process([
                "grep -v '^search ' /etc/resolv.conf > /etc/resolv.conf.tmp && echo " . escapeshellarg($searchLine) . ' >> /etc/resolv.conf.tmp && mv /etc/resolv.conf.tmp /etc/resolv.conf',
            ], $this->server, false);

            $this->newSearchDomain = '';
            $this->loadDnsData();
            $this->dispatch('success', 'Search domain added: ' . $domain);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeSearchDomain(int $index): void
    {
        try {
            $this->authorize('update', $this->server);

            if (! isset($this->searchDomains[$index])) {
                $this->dispatch('error', 'Search domain not found.');

                return;
            }

            $remaining = $this->searchDomains;
            array_splice($remaining, $index, 1);

            $cmd = "grep -v '^search ' /etc/resolv.conf > /etc/resolv.conf.tmp";
            if (! empty($remaining)) {
                $cmd .= ' && echo ' . escapeshellarg('search ' . implode(' ', $remaining)) . ' >> /etc/resolv.conf.tmp';
            }
            $cmd .= ' && mv /etc/resolv.conf.tmp /etc/resolv.conf';

            instant_remote_process([$cmd], $this->server, false);

            $this->loadDnsData();
            $this->dispatch('success', 'Search domain removed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  RAW resolv.conf EDIT                                   ║
    // ╚══════════════════════════════════════════════════════════╝

    public function toggleRawEdit(): void
    {
        $this->rawEditMode = ! $this->rawEditMode;
        if ($this->rawEditMode) {
            $this->resolvConfEdit = $this->resolvConfContent;
        }
    }

    public function saveResolvConf(): void
    {
        try {
            $this->authorize('update', $this->server);

            $content = trim($this->resolvConfEdit);
            if (empty($content)) {
                $this->dispatch('error', 'resolv.conf content cannot be empty.');

                return;
            }

            if (! preg_match('/nameserver\s+/', $content)) {
                $this->dispatch('error', 'resolv.conf must contain at least one nameserver entry.');

                return;
            }

            $encoded = base64_encode($content . "\n");
            instant_remote_process(['echo ' . escapeshellarg($encoded) . ' | base64 -d > /etc/resolv.conf'], $this->server, false);

            $this->rawEditMode = false;
            $this->loadDnsData();
            $this->dispatch('success', 'resolv.conf updated successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  DNS SERVER (BIND9) MANAGEMENT                          ║
    // ╚══════════════════════════════════════════════════════════╝

    public function detectDnsServer(): void
    {
        try {
            $output = trim(instant_remote_process([
                'echo "===WHICH===" && (which named 2>/dev/null || echo "NOT_FOUND")',
                'echo "===VERSION===" && (named -v 2>/dev/null || echo "NOT_FOUND")',
                'echo "===STATUS===" && (systemctl is-active named 2>/dev/null || systemctl is-active bind9 2>/dev/null || echo "inactive")',
            ], $this->server, false));

            $sections = $this->parseSections($output, ['===WHICH===', '===VERSION===', '===STATUS===']);

            $which = trim($sections['===WHICH==='] ?? 'NOT_FOUND');
            $this->dnsServerInstalled = $which !== 'NOT_FOUND' && ! empty($which);
            $this->dnsServerVersion = trim($sections['===VERSION==='] ?? '');
            if ($this->dnsServerVersion === 'NOT_FOUND') {
                $this->dnsServerVersion = '';
            }
            $this->dnsServerStatus = trim($sections['===STATUS==='] ?? 'inactive');

            if ($this->dnsServerInstalled) {
                $this->loadZones();
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function installDnsServer(): void
    {
        try {
            $this->authorize('update', $this->server);

            instant_remote_process([
                'export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && apt-get install -y -qq bind9 bind9utils bind9-doc dnsutils > /dev/null 2>&1',
            ], $this->server, false);

            // Create zones directory
            instant_remote_process([
                'mkdir -p ' . $this->bindZonePath,
                'chown bind:bind ' . $this->bindZonePath,
            ], $this->server, false);

            // Ensure named.conf.local includes our zones directory
            $includeCheck = trim(instant_remote_process([
                'grep -c "include.*zones" ' . $this->bindConfigPath . ' 2>/dev/null || echo "0"',
            ], $this->server, false));

            if ($includeCheck === '0') {
                instant_remote_process([
                    'echo "" >> ' . $this->bindConfigPath,
                    'echo "// Coolify managed zones" >> ' . $this->bindConfigPath,
                    'echo "include \"' . $this->bindZonePath . '/named.conf.coolify\";" >> ' . $this->bindConfigPath,
                    'touch ' . $this->bindZonePath . '/named.conf.coolify',
                    'chown bind:bind ' . $this->bindZonePath . '/named.conf.coolify',
                ], $this->server, false);
            }

            // Enable and start
            instant_remote_process([
                'systemctl enable bind9 2>/dev/null || systemctl enable named 2>/dev/null',
                'systemctl start bind9 2>/dev/null || systemctl start named 2>/dev/null',
            ], $this->server, false);

            $this->detectDnsServer();
            $this->dispatch('success', 'BIND9 DNS server installed and started successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function startDnsServer(): void
    {
        try {
            $this->authorize('update', $this->server);
            instant_remote_process(['systemctl start bind9 2>/dev/null || systemctl start named 2>/dev/null'], $this->server, false);
            $this->detectDnsServer();
            $this->dispatch('success', 'DNS server started.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function stopDnsServer(): void
    {
        try {
            $this->authorize('update', $this->server);
            instant_remote_process(['systemctl stop bind9 2>/dev/null || systemctl stop named 2>/dev/null'], $this->server, false);
            $this->detectDnsServer();
            $this->dispatch('success', 'DNS server stopped.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function restartDnsServer(): void
    {
        try {
            $this->authorize('update', $this->server);
            instant_remote_process(['systemctl restart bind9 2>/dev/null || systemctl restart named 2>/dev/null'], $this->server, false);
            $this->detectDnsServer();
            $this->dispatch('success', 'DNS server restarted.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  ZONE MANAGEMENT                                        ║
    // ╚══════════════════════════════════════════════════════════╝

    public function loadZones(): void
    {
        try {
            $coolifyConf = $this->bindZonePath . '/named.conf.coolify';
            $content = trim(instant_remote_process(
                ['cat ' . escapeshellarg($coolifyConf) . ' 2>/dev/null || echo ""'],
                $this->server,
                false
            ));

            $this->zones = [];
            if (! empty($content)) {
                // Parse zone declarations: zone "example.com" { type master; file "/etc/bind/zones/db.example.com"; };
                preg_match_all('/zone\s+"([^"]+)"\s*\{([^}]+)\}/', $content, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $domain = $match[1];
                    $body = $match[2];

                    $type = 'master';
                    if (preg_match('/type\s+(\w+)/', $body, $tm)) {
                        $type = $tm[1];
                    }

                    $file = '';
                    if (preg_match('/file\s+"([^"]+)"/', $body, $fm)) {
                        $file = $fm[1];
                    }

                    $masters = '';
                    if (preg_match('/masters\s*\{([^}]+)\}/', $body, $mm)) {
                        $masters = trim($mm[1], " \t\n\r\0\x0B;");
                    }

                    $this->zones[] = [
                        'domain' => $domain,
                        'type' => $type,
                        'file' => $file,
                        'masters' => $masters,
                    ];
                }
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function createZone(): void
    {
        try {
            $this->authorize('update', $this->server);

            $domain = trim($this->newZoneDomain);
            if (empty($domain)) {
                $this->dispatch('error', 'Domain name is required.');

                return;
            }

            if (! $this->isValidDomain($domain)) {
                $this->dispatch('error', 'Invalid domain format.');

                return;
            }

            // Check duplicate
            foreach ($this->zones as $zone) {
                if ($zone['domain'] === $domain) {
                    $this->dispatch('error', 'Zone already exists: ' . $domain);

                    return;
                }
            }

            $zoneFile = $this->bindZonePath . '/db.' . $domain;
            $type = $this->newZoneType;
            $adminEmail = str_replace('@', '.', trim($this->newZoneAdminEmail));
            if (empty($adminEmail)) {
                $adminEmail = 'admin';
            }
            $serial = date('Ymd') . '01';

            if ($type === 'master') {
                // Create zone file with SOA and default records
                $serverIp = $this->server->ip;
                $zoneContent = <<<ZONE
\$TTL 86400
@   IN  SOA ns1.{$domain}. {$adminEmail}.{$domain}. (
            {$serial}  ; Serial
            3600       ; Refresh (1 hour)
            1800       ; Retry (30 minutes)
            604800     ; Expire (1 week)
            86400 )    ; Minimum TTL (1 day)

; Nameservers
@   IN  NS  ns1.{$domain}.
@   IN  NS  ns2.{$domain}.

; Glue records
ns1 IN  A   {$serverIp}
ns2 IN  A   {$serverIp}

; Default records
@   IN  A   {$serverIp}
www IN  CNAME   @
ZONE;

                $encoded = base64_encode($zoneContent);
                instant_remote_process([
                    'echo ' . escapeshellarg($encoded) . ' | base64 -d > ' . escapeshellarg($zoneFile),
                    'chown bind:bind ' . escapeshellarg($zoneFile),
                    'chmod 644 ' . escapeshellarg($zoneFile),
                ], $this->server, false);

                // Add zone to coolify config
                $zoneDecl = "\nzone \"{$domain}\" {\n    type master;\n    file \"{$zoneFile}\";\n    allow-transfer { any; };\n};\n";
            } else {
                // Slave zone
                $masterIp = trim($this->newZoneMasterIp);
                if (empty($masterIp) || ! filter_var($masterIp, FILTER_VALIDATE_IP)) {
                    $this->dispatch('error', 'Valid master IP is required for slave zones.');

                    return;
                }

                $zoneDecl = "\nzone \"{$domain}\" {\n    type slave;\n    file \"{$zoneFile}\";\n    masters { {$masterIp}; };\n};\n";
            }

            $coolifyConf = $this->bindZonePath . '/named.conf.coolify';
            instant_remote_process([
                'echo ' . escapeshellarg($zoneDecl) . ' >> ' . escapeshellarg($coolifyConf),
            ], $this->server, false);

            $this->reloadBind();

            $this->newZoneDomain = '';
            $this->newZoneMasterIp = '';
            $this->newZoneAdminEmail = 'admin';
            $this->loadZones();
            $this->dispatch('success', 'DNS zone created: ' . $domain);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deleteZone(string $domain): void
    {
        try {
            $this->authorize('update', $this->server);

            if (empty($domain)) {
                return;
            }

            $coolifyConf = $this->bindZonePath . '/named.conf.coolify';
            $zoneFile = $this->bindZonePath . '/db.' . $domain;

            // Remove zone declaration from config (multiline)
            $configContent = trim(instant_remote_process(
                ['cat ' . escapeshellarg($coolifyConf)],
                $this->server,
                false
            ));

            // Remove the zone block for this domain
            $pattern = '/\s*zone\s+"' . preg_quote($domain, '/') . '"\s*\{[^}]+\};\s*/';
            $newConfig = preg_replace($pattern, "\n", $configContent);
            $encoded = base64_encode(trim($newConfig) . "\n");

            instant_remote_process([
                'echo ' . escapeshellarg($encoded) . ' | base64 -d > ' . escapeshellarg($coolifyConf),
                'rm -f ' . escapeshellarg($zoneFile),
            ], $this->server, false);

            $this->reloadBind();

            if ($this->selectedZone === $domain) {
                $this->selectedZone = '';
                $this->selectedZoneRecords = [];
            }

            $this->loadZones();
            $this->dispatch('success', 'DNS zone deleted: ' . $domain);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function selectZone(string $domain): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->selectedZone = $domain;
            $this->editingRecordIndex = null;
            $this->zoneRawEditMode = false;
            $this->loadZoneRecords($domain);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deselectZone(): void
    {
        $this->selectedZone = '';
        $this->selectedZoneRecords = [];
        $this->editingRecordIndex = null;
        $this->zoneRawEditMode = false;
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  ZONE RECORDS (BIND9 Zone File CRUD)                    ║
    // ╚══════════════════════════════════════════════════════════╝

    public function loadZoneRecords(string $domain): void
    {
        try {
            $zoneFile = $this->bindZonePath . '/db.' . $domain;
            $content = trim(instant_remote_process(
                ['cat ' . escapeshellarg($zoneFile) . ' 2>/dev/null || echo ""'],
                $this->server,
                false
            ));

            $this->zoneFileContent = $content;
            $this->selectedZoneRecords = $this->parseZoneFile($content, $domain);

            // Extract serial
            if (preg_match('/(\d{10})\s*;\s*Serial/', $content, $sm)) {
                $this->selectedZoneSerial = $sm[1];
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Parse a BIND zone file into structured records.
     *
     * @return array<int, array{name: string, ttl: string, class: string, type: string, value: string, priority: string, raw: string}>
     */
    private function parseZoneFile(string $content, string $domain): array
    {
        $records = [];
        $defaultTtl = '86400';
        $lastName = '@';
        $inSoa = false;
        $soaLines = '';

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            // Skip empty lines and pure comments
            if (empty($trimmed) || str_starts_with($trimmed, ';')) {
                continue;
            }

            // $TTL directive
            if (preg_match('/^\$TTL\s+(\d+)/i', $trimmed, $m)) {
                $defaultTtl = $m[1];

                continue;
            }

            // Skip other directives ($ORIGIN, $INCLUDE, etc.)
            if (str_starts_with($trimmed, '$')) {
                continue;
            }

            // Handle multiline SOA record - skip it from records list
            if ($inSoa) {
                $soaLines .= ' ' . $trimmed;
                if (str_contains($trimmed, ')')) {
                    $inSoa = false;
                    // Add SOA as a single record
                    $records[] = [
                        'name' => '@',
                        'ttl' => $defaultTtl,
                        'class' => 'IN',
                        'type' => 'SOA',
                        'value' => $this->cleanSoaValue($soaLines),
                        'priority' => '',
                        'raw' => 'SOA',
                        'editable' => false,
                    ];
                }

                continue;
            }

            if (preg_match('/\bSOA\b/', $trimmed) && str_contains($trimmed, '(')) {
                $inSoa = true;
                $soaLines = $trimmed;
                if (str_contains($trimmed, ')')) {
                    $inSoa = false;
                    $records[] = [
                        'name' => '@',
                        'ttl' => $defaultTtl,
                        'class' => 'IN',
                        'type' => 'SOA',
                        'value' => $this->cleanSoaValue($soaLines),
                        'priority' => '',
                        'raw' => 'SOA',
                        'editable' => false,
                    ];
                }

                continue;
            }

            // Strip inline comments
            $noComment = preg_replace('/\s*;.*$/', '', $trimmed);
            if (empty($noComment)) {
                continue;
            }

            // Parse resource record line
            $record = $this->parseResourceRecord($noComment, $lastName, $defaultTtl);
            if ($record !== null) {
                $lastName = $record['name'];
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Parse a single resource record line from a zone file.
     *
     * @return array{name: string, ttl: string, class: string, type: string, value: string, priority: string, raw: string, editable: bool}|null
     */
    private function parseResourceRecord(string $line, string $lastName, string $defaultTtl): ?array
    {
        $validTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR', 'SOA', 'DNAME', 'NAPTR', 'SPF', 'DMARC'];
        $parts = preg_split('/\s+/', $line);
        if (count($parts) < 2) {
            return null;
        }

        $name = $lastName;
        $ttl = $defaultTtl;
        $class = 'IN';
        $type = '';
        $valueStart = 0;
        $priority = '';

        $idx = 0;

        // First token: name, TTL, class, or type
        if (! is_numeric($parts[0]) && ! in_array(strtoupper($parts[0]), ['IN', 'CH', 'HS']) && ! in_array(strtoupper($parts[0]), $validTypes)) {
            $name = $parts[0];
            $idx = 1;
        }

        // Optional TTL
        if (isset($parts[$idx]) && is_numeric($parts[$idx])) {
            $ttl = $parts[$idx];
            $idx++;
        }

        // Optional class
        if (isset($parts[$idx]) && in_array(strtoupper($parts[$idx]), ['IN', 'CH', 'HS'])) {
            $class = strtoupper($parts[$idx]);
            $idx++;
        }

        // Type
        if (isset($parts[$idx]) && in_array(strtoupper($parts[$idx]), $validTypes)) {
            $type = strtoupper($parts[$idx]);
            $idx++;
        }

        if (empty($type)) {
            return null;
        }

        // MX and SRV have priority
        if (in_array($type, ['MX', 'SRV']) && isset($parts[$idx]) && is_numeric($parts[$idx])) {
            $priority = $parts[$idx];
            $idx++;
        }

        $value = implode(' ', array_slice($parts, $idx));

        return [
            'name' => $name,
            'ttl' => $ttl,
            'class' => $class,
            'type' => $type,
            'value' => $value,
            'priority' => $priority,
            'raw' => $line,
            'editable' => $type !== 'SOA',
        ];
    }

    private function cleanSoaValue(string $soaLines): string
    {
        // Extract the key parts: primary NS, admin email
        $clean = preg_replace('/[();]/', '', $soaLines);
        $clean = preg_replace('/\s+/', ' ', $clean);
        // Remove SOA keyword and preceding name/class
        $clean = preg_replace('/^.*?SOA\s+/i', '', $clean);

        return trim($clean);
    }

    public function addRecord(): void
    {
        try {
            $this->authorize('update', $this->server);

            if (empty($this->selectedZone)) {
                $this->dispatch('error', 'No zone selected.');

                return;
            }

            $name = trim($this->newRecordName);
            $type = $this->newRecordType;
            $value = trim($this->newRecordValue);
            $ttl = $this->newRecordTtl;
            $priority = $this->newRecordPriority;

            if (empty($name)) {
                $name = '@';
            }

            if (empty($value)) {
                $this->dispatch('error', 'Record value is required.');

                return;
            }

            if (! $this->validateRecordValue($type, $value)) {
                return;
            }

            // Build the record line
            $recordLine = $this->buildRecordLine($name, $ttl, $type, $value, $priority);

            // Append to zone file
            $zoneFile = $this->bindZonePath . '/db.' . $this->selectedZone;
            instant_remote_process([
                'echo ' . escapeshellarg($recordLine) . ' >> ' . escapeshellarg($zoneFile),
            ], $this->server, false);

            $this->incrementSerial($this->selectedZone);
            $this->reloadBind();

            // Reset form
            $this->newRecordName = '';
            $this->newRecordValue = '';
            $this->newRecordTtl = 3600;
            $this->newRecordPriority = 10;

            $this->loadZoneRecords($this->selectedZone);
            $this->dispatch('success', $type . ' record added successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function startEditRecord(int $index): void
    {
        if (! isset($this->selectedZoneRecords[$index])) {
            return;
        }

        $record = $this->selectedZoneRecords[$index];
        if (! ($record['editable'] ?? true)) {
            $this->dispatch('error', 'SOA records cannot be edited directly.');

            return;
        }

        $this->editingRecordIndex = $index;
        $this->editRecordName = $record['name'];
        $this->editRecordType = $record['type'];
        $this->editRecordValue = $record['value'];
        $this->editRecordTtl = (int) $record['ttl'];
        $this->editRecordPriority = (int) ($record['priority'] ?: 10);
    }

    public function cancelEditRecord(): void
    {
        $this->editingRecordIndex = null;
    }

    public function updateRecord(): void
    {
        try {
            $this->authorize('update', $this->server);

            if ($this->editingRecordIndex === null || empty($this->selectedZone)) {
                return;
            }

            $value = trim($this->editRecordValue);
            if (empty($value)) {
                $this->dispatch('error', 'Record value is required.');

                return;
            }

            if (! $this->validateRecordValue($this->editRecordType, $value)) {
                return;
            }

            // Rebuild the entire zone file
            $this->rebuildZoneFileWithEdit(
                $this->editingRecordIndex,
                $this->editRecordName,
                $this->editRecordTtl,
                $this->editRecordType,
                $value,
                $this->editRecordPriority
            );

            $this->editingRecordIndex = null;
            $this->dispatch('success', 'Record updated successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deleteRecord(int $index): void
    {
        try {
            $this->authorize('update', $this->server);

            if (! isset($this->selectedZoneRecords[$index]) || empty($this->selectedZone)) {
                $this->dispatch('error', 'Record not found.');

                return;
            }

            $record = $this->selectedZoneRecords[$index];
            if ($record['type'] === 'SOA') {
                $this->dispatch('error', 'Cannot delete SOA record.');

                return;
            }

            $this->rebuildZoneFileWithDelete($index);
            $this->dispatch('success', $record['type'] . ' record deleted.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function buildRecordLine(string $name, int $ttl, string $type, string $value, int $priority): string
    {
        $line = "{$name}\t{$ttl}\tIN\t{$type}";
        if (in_array($type, ['MX', 'SRV'])) {
            $line .= "\t{$priority}";
        }
        $line .= "\t{$value}";

        return $line;
    }

    /**
     * Rebuild zone file with one record edited.
     */
    private function rebuildZoneFileWithEdit(int $editIndex, string $name, int $ttl, string $type, string $value, int $priority): void
    {
        $zoneFile = $this->bindZonePath . '/db.' . $this->selectedZone;
        $content = $this->zoneFileContent;
        $lines = explode("\n", $content);

        // Map parsed record indices to actual line numbers in the file
        $recordLineMap = $this->mapRecordIndicesToLines($content);

        if (! isset($recordLineMap[$editIndex])) {
            $this->dispatch('error', 'Could not locate record in zone file.');

            return;
        }

        $lineNum = $recordLineMap[$editIndex];
        $newLine = $this->buildRecordLine($name, $ttl, $type, $value, $priority);
        $lines[$lineNum] = $newLine;

        $newContent = implode("\n", $lines);
        $this->writeZoneFile($this->selectedZone, $newContent);
        $this->incrementSerial($this->selectedZone);
        $this->reloadBind();
        $this->loadZoneRecords($this->selectedZone);
    }

    /**
     * Rebuild zone file with one record deleted.
     */
    private function rebuildZoneFileWithDelete(int $deleteIndex): void
    {
        $content = $this->zoneFileContent;
        $lines = explode("\n", $content);

        $recordLineMap = $this->mapRecordIndicesToLines($content);

        if (! isset($recordLineMap[$deleteIndex])) {
            $this->dispatch('error', 'Could not locate record in zone file.');

            return;
        }

        $lineNum = $recordLineMap[$deleteIndex];
        unset($lines[$lineNum]);

        $newContent = implode("\n", $lines);
        $this->writeZoneFile($this->selectedZone, $newContent);
        $this->incrementSerial($this->selectedZone);
        $this->reloadBind();
        $this->loadZoneRecords($this->selectedZone);
    }

    /**
     * Map each parsed record index to its line number in the zone file.
     *
     * @return array<int, int>
     */
    private function mapRecordIndicesToLines(string $content): array
    {
        $map = [];
        $recordIdx = 0;
        $inSoa = false;
        $validTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR', 'SOA', 'DNAME', 'NAPTR', 'SPF', 'DMARC'];

        foreach (explode("\n", $content) as $lineNum => $line) {
            $trimmed = trim($line);

            if (empty($trimmed) || str_starts_with($trimmed, ';') || str_starts_with($trimmed, '$')) {
                continue;
            }

            if ($inSoa) {
                if (str_contains($trimmed, ')')) {
                    $inSoa = false;
                    // SOA record gets an index but we don't map it for editing
                    $recordIdx++;
                }

                continue;
            }

            if (preg_match('/\bSOA\b/', $trimmed) && str_contains($trimmed, '(')) {
                $inSoa = true;
                if (str_contains($trimmed, ')')) {
                    $inSoa = false;
                    $recordIdx++;
                }

                continue;
            }

            // Strip inline comments
            $noComment = preg_replace('/\s*;.*$/', '', $trimmed);
            if (empty($noComment)) {
                continue;
            }

            // Check if this looks like a resource record
            $parts = preg_split('/\s+/', $noComment);
            $hasType = false;
            foreach ($parts as $p) {
                if (in_array(strtoupper($p), $validTypes)) {
                    $hasType = true;

                    break;
                }
            }

            if ($hasType) {
                $map[$recordIdx] = $lineNum;
                $recordIdx++;
            }
        }

        return $map;
    }

    private function writeZoneFile(string $domain, string $content): void
    {
        $zoneFile = $this->bindZonePath . '/db.' . $domain;
        $encoded = base64_encode($content);
        instant_remote_process([
            'echo ' . escapeshellarg($encoded) . ' | base64 -d > ' . escapeshellarg($zoneFile),
            'chown bind:bind ' . escapeshellarg($zoneFile),
        ], $this->server, false);
    }

    private function incrementSerial(string $domain): void
    {
        try {
            $zoneFile = $this->bindZonePath . '/db.' . $domain;
            $content = trim(instant_remote_process(
                ['cat ' . escapeshellarg($zoneFile)],
                $this->server,
                false
            ));

            $today = date('Ymd');

            $newContent = preg_replace_callback(
                '/(\d{8})(\d{2})(\s*;\s*Serial)/',
                function ($matches) use ($today) {
                    $datepart = $matches[1];
                    $counter = (int) $matches[2];

                    if ($datepart === $today) {
                        $counter++;
                    } else {
                        $datepart = $today;
                        $counter = 1;
                    }

                    return $datepart . str_pad((string) $counter, 2, '0', STR_PAD_LEFT) . $matches[3];
                },
                $content
            );

            if ($newContent !== $content) {
                $this->writeZoneFile($domain, $newContent);
            }
        } catch (\Throwable $e) {
            // Non-critical, log but don't fail
        }
    }

    private function reloadBind(): void
    {
        instant_remote_process([
            'rndc reload 2>/dev/null || (systemctl reload bind9 2>/dev/null || systemctl reload named 2>/dev/null)',
        ], $this->server, false);
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  ZONE FILE RAW EDIT                                     ║
    // ╚══════════════════════════════════════════════════════════╝

    public function toggleZoneRawEdit(): void
    {
        $this->zoneRawEditMode = ! $this->zoneRawEditMode;
        if ($this->zoneRawEditMode && ! empty($this->selectedZone)) {
            $zoneFile = $this->bindZonePath . '/db.' . $this->selectedZone;
            $this->zoneFileContent = trim(instant_remote_process(
                ['cat ' . escapeshellarg($zoneFile) . ' 2>/dev/null || echo ""'],
                $this->server,
                false
            ));
        }
    }

    public function saveZoneFile(): void
    {
        try {
            $this->authorize('update', $this->server);

            if (empty($this->selectedZone) || empty(trim($this->zoneFileContent))) {
                $this->dispatch('error', 'Zone file content cannot be empty.');

                return;
            }

            $this->writeZoneFile($this->selectedZone, trim($this->zoneFileContent) . "\n");

            // Validate zone
            $zoneFile = $this->bindZonePath . '/db.' . $this->selectedZone;
            $check = trim(instant_remote_process([
                'named-checkzone ' . escapeshellarg($this->selectedZone) . ' ' . escapeshellarg($zoneFile) . ' 2>&1',
            ], $this->server, false));

            if (! str_contains($check, 'OK')) {
                $this->dispatch('error', 'Zone validation failed: ' . $check);

                return;
            }

            $this->reloadBind();
            $this->zoneRawEditMode = false;
            $this->loadZoneRecords($this->selectedZone);
            $this->dispatch('success', 'Zone file saved and validated successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  ZONE IMPORT / EXPORT                                   ║
    // ╚══════════════════════════════════════════════════════════╝

    public string $importZoneContent = '';
    public string $importZoneDomain = '';

    public function exportZone(string $domain): void
    {
        try {
            $this->authorize('update', $this->server);
            $zoneFile = $this->bindZonePath . '/db.' . $domain;
            $content = trim(instant_remote_process(
                ['cat ' . escapeshellarg($zoneFile) . ' 2>/dev/null || echo ""'],
                $this->server,
                false
            ));

            if (empty($content)) {
                $this->dispatch('error', 'Zone file is empty or not found.');

                return;
            }

            $this->zoneFileContent = $content;
            $this->selectedZone = $domain;
            $this->zoneRawEditMode = true;
            $this->dispatch('success', 'Zone file exported. You can copy the content below.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function importZone(): void
    {
        try {
            $this->authorize('update', $this->server);

            $domain = trim($this->importZoneDomain);
            $content = trim($this->importZoneContent);

            if (empty($domain) || empty($content)) {
                $this->dispatch('error', 'Both domain name and zone file content are required.');

                return;
            }

            if (! $this->isValidDomain($domain)) {
                $this->dispatch('error', 'Invalid domain format.');

                return;
            }

            // Create zone file
            $zoneFile = $this->bindZonePath . '/db.' . $domain;
            $encoded = base64_encode($content . "\n");
            instant_remote_process([
                'echo ' . escapeshellarg($encoded) . ' | base64 -d > ' . escapeshellarg($zoneFile),
                'chown bind:bind ' . escapeshellarg($zoneFile),
            ], $this->server, false);

            // Validate
            $check = trim(instant_remote_process([
                'named-checkzone ' . escapeshellarg($domain) . ' ' . escapeshellarg($zoneFile) . ' 2>&1',
            ], $this->server, false));

            if (! str_contains($check, 'OK')) {
                instant_remote_process(['rm -f ' . escapeshellarg($zoneFile)], $this->server, false);
                $this->dispatch('error', 'Invalid zone file: ' . $check);

                return;
            }

            // Check if zone already exists in config
            $exists = false;
            foreach ($this->zones as $zone) {
                if ($zone['domain'] === $domain) {
                    $exists = true;

                    break;
                }
            }

            if (! $exists) {
                $coolifyConf = $this->bindZonePath . '/named.conf.coolify';
                $zoneDecl = "\nzone \"{$domain}\" {\n    type master;\n    file \"{$zoneFile}\";\n    allow-transfer { any; };\n};\n";
                instant_remote_process([
                    'echo ' . escapeshellarg($zoneDecl) . ' >> ' . escapeshellarg($coolifyConf),
                ], $this->server, false);
            }

            $this->reloadBind();
            $this->importZoneDomain = '';
            $this->importZoneContent = '';
            $this->loadZones();
            $this->dispatch('success', 'Zone imported successfully: ' . $domain);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  DNS LOOKUP                                             ║
    // ╚══════════════════════════════════════════════════════════╝

    public function dnsLookup(): void
    {
        try {
            $this->authorize('update', $this->server);

            $domain = trim($this->lookupDomain);
            if (empty($domain)) {
                $this->dispatch('error', 'Domain name is required.');

                return;
            }

            if (! $this->isValidDomain($domain)) {
                $this->dispatch('error', 'Invalid domain format.');

                return;
            }

            $type = escapeshellarg($this->lookupType);
            $domainArg = escapeshellarg($domain);
            $cmd = "dig +noall +answer +authority +additional {$domainArg} {$type}";

            $server = trim($this->lookupServer);
            if (! empty($server)) {
                if (! filter_var($server, FILTER_VALIDATE_IP) && ! $this->isValidDomain($server)) {
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
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  DNS ZONE QUERY (EXTERNAL)                              ║
    // ╚══════════════════════════════════════════════════════════╝

    public function queryZoneRecords(): void
    {
        try {
            $this->authorize('update', $this->server);

            $domain = trim($this->zoneDomain);
            if (empty($domain)) {
                $this->dispatch('error', 'Domain name is required.');

                return;
            }

            if (! $this->isValidDomain($domain)) {
                $this->dispatch('error', 'Invalid domain format.');

                return;
            }

            $domainArg = escapeshellarg($domain);
            $this->zoneRecords = [];
            $types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA', 'CAA', 'SRV'];

            // Query all record types in a single SSH call
            $commands = [];
            foreach ($types as $type) {
                $typeArg = escapeshellarg($type);
                $commands[] = "echo '===TYPE_{$type}===' && dig +noall +answer {$domainArg} {$typeArg} 2>/dev/null";
            }

            $output = trim(instant_remote_process([implode(' && ', $commands)], $this->server, false));
            $typeSections = [];
            $currentType = null;

            foreach (explode("\n", $output) as $line) {
                if (preg_match('/^===TYPE_(\w+)===$/', trim($line), $m)) {
                    $currentType = $m[1];
                    $typeSections[$currentType] = '';

                    continue;
                }
                if ($currentType !== null) {
                    $typeSections[$currentType] .= $line . "\n";
                }
            }

            foreach ($typeSections as $type => $result) {
                $result = trim($result);
                if (empty($result)) {
                    continue;
                }

                foreach (explode("\n", $result) as $line) {
                    $line = trim($line);
                    if (empty($line) || str_starts_with($line, ';')) {
                        continue;
                    }

                    $parts = preg_split('/\s+/', $line, 5);
                    if (count($parts) >= 5) {
                        $record = [
                            'name' => rtrim($parts[0], '.'),
                            'ttl' => (int) $parts[1],
                            'class' => $parts[2],
                            'type' => $parts[3],
                            'value' => $parts[4],
                        ];

                        if ($parts[3] === 'MX') {
                            $mxParts = explode(' ', $parts[4], 2);
                            $record['priority'] = (int) ($mxParts[0] ?? 0);
                            $record['value'] = $mxParts[1] ?? $parts[4];
                        }

                        $this->zoneRecords[] = $record;
                    }
                }
            }

            if (empty($this->zoneRecords)) {
                $this->dispatch('error', 'No DNS records found for ' . $domain);
            } else {
                $this->dispatch('success', count($this->zoneRecords) . ' DNS records found for ' . $domain);
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  DNS PROPAGATION CHECK                                  ║
    // ╚══════════════════════════════════════════════════════════╝

    public function checkPropagation(): void
    {
        try {
            $this->authorize('update', $this->server);

            $domain = trim($this->propagationDomain);
            if (empty($domain)) {
                $this->dispatch('error', 'Domain name is required.');

                return;
            }

            if (! $this->isValidDomain($domain)) {
                $this->dispatch('error', 'Invalid domain format.');

                return;
            }

            $this->propagationResults = [];
            $domainArg = escapeshellarg($domain);
            $typeArg = escapeshellarg($this->propagationType);

            // Build a single command that queries all DNS servers in parallel
            $parts = [];
            foreach ($this->publicDnsServers as $dns) {
                $serverArg = escapeshellarg($dns['ip']);
                $nameArg = escapeshellarg($dns['name']);
                $parts[] = "(result=\$(dig +noall +answer +short @{$serverArg} {$domainArg} {$typeArg} +time=3 +tries=1 2>/dev/null) && echo {$nameArg}\"|||\"" . escapeshellarg($dns['ip']) . "\"|||\"" . '${result:-NO_RECORD}' . " || echo {$nameArg}\"|||\"" . escapeshellarg($dns['ip']) . "\"|||TIMEOUT\")";
            }
            $cmd = implode(' & ', $parts) . ' & wait';

            $output = trim(instant_remote_process([$cmd], $this->server, false));

            if (! empty($output)) {
                foreach (explode("\n", $output) as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }
                    $segments = explode('|||', $line, 3);
                    if (count($segments) === 3) {
                        $result = trim($segments[2]);
                        $this->propagationResults[] = [
                            'server' => trim($segments[0]),
                            'ip' => trim($segments[1]),
                            'result' => $result === 'NO_RECORD' ? 'No record' : $result,
                            'status' => in_array($result, ['NO_RECORD', 'TIMEOUT']) ? 'error' : 'success',
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  VALIDATION HELPERS                                     ║
    // ╚══════════════════════════════════════════════════════════╝

    private function isValidHostname(string $hostname): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $hostname);
    }

    private function isValidDomain(string $domain): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $domain);
    }

    private function validateRecordValue(string $type, string $value): bool
    {
        switch ($type) {
            case 'A':
                if (! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $this->dispatch('error', 'A record requires a valid IPv4 address.');

                    return false;
                }

                break;
            case 'AAAA':
                if (! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $this->dispatch('error', 'AAAA record requires a valid IPv6 address.');

                    return false;
                }

                break;
            case 'CNAME':
            case 'NS':
            case 'PTR':
            case 'DNAME':
                if (! $this->isValidDomain(rtrim($value, '.'))) {
                    $this->dispatch('error', $type . ' record requires a valid domain name.');

                    return false;
                }

                break;
            case 'MX':
                $mxTarget = rtrim($value, '.');
                if (! $this->isValidDomain($mxTarget)) {
                    $this->dispatch('error', 'MX record requires a valid mail server hostname.');

                    return false;
                }

                break;
            case 'TXT':
                // TXT records can contain anything, but should be quoted
                break;
            case 'SRV':
                // SRV format: weight port target
                $srvParts = preg_split('/\s+/', $value);
                if (count($srvParts) < 3) {
                    $this->dispatch('error', 'SRV record format: weight port target (e.g., "0 443 server.example.com.")');

                    return false;
                }

                break;
            case 'CAA':
                // CAA format: flags tag value (e.g., 0 issue "letsencrypt.org")
                $caaParts = preg_split('/\s+/', $value, 3);
                if (count($caaParts) < 3) {
                    $this->dispatch('error', 'CAA record format: flags tag value (e.g., 0 issue "letsencrypt.org")');

                    return false;
                }

                break;
        }

        return true;
    }

    // ╔══════════════════════════════════════════════════════════╗
    // ║  RENDER                                                 ║
    // ╚══════════════════════════════════════════════════════════╝

    public function render()
    {
        return view('livewire.server.dns-manager');
    }
}
