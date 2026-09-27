<?php

namespace App\Actions\Shared;

use App\Models\Server;
use App\Support\CloudflareHttpTunnel;
use Lorisleiva\Actions\Concerns\AsAction;
use PurplePixie\PhpDns\DNSQuery;
use PurplePixie\PhpDns\DNSTypes;
use Spatie\Url\Url;

class CheckDomainDns
{
    use AsAction;

    /**
     * @param  array<string, string>  $entries
     * @return array<string, array{status: string, message: string, expected_ip: ?string, checked_at: string}>
     */
    public function handle(
        array $entries,
        ?Server $server,
        ?string $expectedIp,
        bool $skipForMultipleServers = false,
        int $timeoutSeconds = 5,
    ): array {
        if (! data_get(instanceSettings(), 'is_dns_validation_enabled')) {
            return $this->sameResultForAll($entries, 'skipped', 'DNS validation is disabled in instance settings.', $expectedIp);
        }

        if (! $server) {
            return $this->sameResultForAll($entries, 'skipped', 'No server available for DNS validation.', null);
        }

        if ($skipForMultipleServers) {
            return $this->sameResultForAll($entries, 'skipped', 'DNS check skipped for multi-server applications.', $expectedIp);
        }

        $deadline = hrtime(true) + ($timeoutSeconds * 1_000_000_000);
        $dnsServers = str(data_get(instanceSettings(), 'custom_dns_servers'))
            ->explode(',')
            ->map(fn ($dnsServer) => trim((string) $dnsServer))
            ->filter()
            ->values();
        $results = [];

        foreach ($entries as $key => $url) {
            $results[$key] = $this->check($url, $server, $expectedIp, $dnsServers->all(), $deadline);
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $dnsServers
     * @return array{status: string, message: string, expected_ip: ?string, checked_at: string}
     */
    private function check(string $url, Server $server, ?string $expectedIp, array $dnsServers, int $deadline): array
    {
        $tunnelMode = $server->isCloudflareHttpTunnel();
        $cnameTarget = $tunnelMode ? $server->cloudflareHttpTunnelCname() : null;
        $resultExpectedIp = $tunnelMode ? $cnameTarget : $expectedIp;

        try {
            $host = Url::fromString($url)->getHost();
        } catch (\Throwable) {
            return $this->result('failed', 'Could not validate DNS for this domain.', $resultExpectedIp);
        }
        if (str($host)->contains('sslip.io')) {
            if ($tunnelMode) {
                return $this->result('failed', CloudflareHttpTunnel::mismatchGuidance($cnameTarget), $resultExpectedIp);
            }

            return $this->result('ok', 'DNS looks correct.', $resultExpectedIp);
        }

        if ($tunnelMode && $this->tunnelDnsLooksCorrect($host, $dnsServers, $deadline, $cnameTarget)) {
            return $this->result('ok', CloudflareHttpTunnel::successMessage($cnameTarget), $resultExpectedIp);
        }

        $type = dnsRecordTypeForIp($expectedIp) === 'AAAA' ? DNSTypes::NAME_AAAA : DNSTypes::NAME_A;
        $receivedAddressRecord = false;

        foreach ($dnsServers as $dnsServer) {
            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds < 1_000_000_000) {
                return $this->result('failed', 'Could not validate DNS for this domain.', $resultExpectedIp);
            }

            try {
                $query = app()->make(DNSQuery::class, [
                    'server' => $dnsServer,
                    'port' => 53,
                    'timeout' => min(5, (int) floor($remainingNanoseconds / 1_000_000_000)),
                ]);
                $records = $query->query($host, $type);

                if ($records === false || $query->hasError()) {
                    continue;
                }

                foreach ($records as $record) {
                    if ($record->getType() !== $type) {
                        continue;
                    }

                    $receivedAddressRecord = true;
                    $resolved = (string) $record->getData();
                    if (isCloudflareIp($resolved)) {
                        return $this->result('ok', $this->successMessage($server, $expectedIp, $tunnelMode, $cnameTarget), $resultExpectedIp);
                    }
                    if (! $tunnelMode && $expectedIp && $resolved === $expectedIp) {
                        return $this->result('ok', $this->successMessage($server, $expectedIp), $resultExpectedIp);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if (! $receivedAddressRecord && hrtime(true) < $deadline) {
            foreach ($this->resolveWithSystemDns($host, $type) as $resolvedIp) {
                if (isCloudflareIp($resolvedIp) || (! $tunnelMode && $expectedIp && $resolvedIp === $expectedIp)) {
                    return $this->result('ok', $this->successMessage($server, $expectedIp, $tunnelMode, $cnameTarget), $resultExpectedIp);
                }
            }
        }

        if ($tunnelMode) {
            return $this->result('failed', CloudflareHttpTunnel::mismatchGuidance($cnameTarget), $resultExpectedIp);
        }

        return $this->result('failed', dnsMismatchGuidanceMessage($expectedIp, $expectedIp), $resultExpectedIp);
    }

    /**
     * @param  array<int, string>  $dnsServers
     */
    private function tunnelDnsLooksCorrect(string $host, array $dnsServers, int $deadline, ?string $expectedCname): bool
    {
        $cnameType = defined(DNSTypes::class.'::NAME_CNAME') ? DNSTypes::NAME_CNAME : 'CNAME';

        foreach ($dnsServers as $dnsServer) {
            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds < 1_000_000_000) {
                return false;
            }

            try {
                $query = app()->make(DNSQuery::class, [
                    'server' => $dnsServer,
                    'port' => 53,
                    'timeout' => min(5, (int) floor($remainingNanoseconds / 1_000_000_000)),
                ]);
                $records = $query->query($host, $cnameType);
                if ($records === false || $query->hasError()) {
                    continue;
                }

                foreach ($records as $record) {
                    $target = (string) $record->getData();
                    if (CloudflareHttpTunnel::isCfargoTarget($target)) {
                        return true;
                    }
                    if (filled($expectedCname) && strtolower(rtrim($target, '.')) === strtolower($expectedCname)) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveWithSystemDns(string $host, string $type): array
    {
        $recordType = $type === DNSTypes::NAME_AAAA ? DNS_AAAA : DNS_A;
        $addressKey = $type === DNSTypes::NAME_AAAA ? 'ipv6' : 'ip';

        try {
            $records = @dns_get_record($host, $recordType);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->pluck($addressKey)
            ->filter(fn ($address) => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false)
            ->values()
            ->all();
    }

    private function successMessage(Server $server, ?string $expectedIp, bool $tunnelMode = false, ?string $cname = null): string
    {
        if ($tunnelMode) {
            return CloudflareHttpTunnel::successMessage($cname);
        }

        if (
            filled($expectedIp)
            && filled($server->ip)
            && $server->ip !== $expectedIp
            && filter_var($server->ip, FILTER_VALIDATE_IP) === false
        ) {
            return "DNS points to {$expectedIp} ({$server->ip}) (or Cloudflare).";
        }

        return $expectedIp ? "DNS points to {$expectedIp} (or Cloudflare)." : 'DNS looks correct.';
    }

    /**
     * @return array{status: string, message: string, expected_ip: ?string, checked_at: string}
     */
    private function result(string $status, string $message, ?string $expectedIp): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'expected_ip' => $expectedIp,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $entries
     * @return array<string, array{status: string, message: string, expected_ip: ?string, checked_at: string}>
     */
    private function sameResultForAll(array $entries, string $status, string $message, ?string $expectedIp): array
    {
        $result = $this->result($status, $message, $expectedIp);

        return array_fill_keys(array_keys($entries), $result);
    }
}
