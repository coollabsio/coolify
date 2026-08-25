<?php

namespace App\Actions\Shared;

use App\Models\Server;
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
        try {
            $host = Url::fromString($url)->getHost();
        } catch (\Throwable) {
            return $this->result('failed', 'Could not validate DNS for this domain.', $expectedIp);
        }
        if (str($host)->contains('sslip.io')) {
            return $this->result('ok', 'DNS looks correct.', $expectedIp);
        }

        $type = dnsRecordTypeForIp($expectedIp) === 'AAAA' ? DNSTypes::NAME_AAAA : DNSTypes::NAME_A;

        foreach ($dnsServers as $dnsServer) {
            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds < 1_000_000_000) {
                return $this->result('failed', 'Could not validate DNS for this domain.', $expectedIp);
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

                    if (isCloudflareIp($record->getData()) || ($expectedIp && $record->getData() === $expectedIp)) {
                        return $this->result('ok', $this->successMessage($server, $expectedIp), $expectedIp);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->result('failed', dnsMismatchGuidanceMessage($expectedIp, $expectedIp), $expectedIp);
    }

    private function successMessage(Server $server, ?string $expectedIp): string
    {
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
