<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareTunnelApi
{
    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function listAccounts(string $token): array
    {
        $accounts = [];
        $page = 1;
        do {
            $response = $this->client($token)->get('https://api.cloudflare.com/client/v4/accounts', [
                'page' => $page,
                'per_page' => 50,
            ]);
            $this->assertSuccess($response->json(), 'Cloudflare accounts could not be listed.');
            foreach ($response->json('result', []) as $account) {
                if (! is_array($account) || ! is_string($account['id'] ?? null)) {
                    continue;
                }
                $accounts[] = [
                    'id' => $account['id'],
                    'name' => (string) ($account['name'] ?? $account['id']),
                ];
            }
            $totalPages = max(1, (int) $response->json('result_info.total_pages', 1));
            $page++;
        } while ($page <= $totalPages);

        return $accounts;
    }

    /**
     * @return array<int, array{id: string, name: string, account_id: string}>
     */
    public function listZones(string $token): array
    {
        $zones = [];
        $page = 1;
        do {
            $response = $this->client($token)->get('https://api.cloudflare.com/client/v4/zones', [
                'page' => $page,
                'per_page' => 50,
            ]);
            $this->assertSuccess($response->json(), 'Cloudflare zones could not be listed.');
            foreach ($response->json('result', []) as $zone) {
                if (! is_array($zone) || ! is_string($zone['id'] ?? null)) {
                    continue;
                }
                $zones[] = [
                    'id' => $zone['id'],
                    'name' => strtolower((string) ($zone['name'] ?? $zone['id'])),
                    'account_id' => (string) data_get($zone, 'account.id', ''),
                ];
            }
            $totalPages = max(1, (int) $response->json('result_info.total_pages', 1));
            $page++;
        } while ($page <= $totalPages);

        return $zones;
    }

    /**
     * @return array<int, array{id: string, name: string, status: string, cname: string}>
     */
    public function listTunnels(string $token, string $accountId): array
    {
        $tunnels = [];
        $page = 1;
        do {
            $response = $this->client($token)->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/cfd_tunnel", [
                'page' => $page,
                'per_page' => 50,
                'is_deleted' => false,
            ]);
            $this->assertSuccess($response->json(), 'Cloudflare tunnels could not be listed.');
            foreach ($response->json('result', []) as $tunnel) {
                if (! is_array($tunnel) || ! is_string($tunnel['id'] ?? null)) {
                    continue;
                }
                $tunnels[] = [
                    'id' => $tunnel['id'],
                    'name' => (string) ($tunnel['name'] ?? $tunnel['id']),
                    'status' => (string) ($tunnel['status'] ?? 'inactive'),
                    'cname' => $tunnel['id'].'.cfargotunnel.com',
                ];
            }
            $totalPages = max(1, (int) $response->json('result_info.total_pages', 1));
            $page++;
        } while ($page <= $totalPages);

        return $tunnels;
    }

    /**
     * @return array{id: string, name: string, status: string, cname: string}
     */
    public function createTunnel(string $token, string $accountId, string $name): array
    {
        $response = $this->client($token)->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/cfd_tunnel", [
            'name' => $name,
            'config_src' => 'cloudflare',
        ]);
        $payload = $response->json();
        $this->assertSuccess($payload, 'Cloudflare could not create the tunnel.');
        $id = (string) data_get($payload, 'result.id');
        if ($id === '') {
            throw new RuntimeException('Cloudflare did not return a tunnel id.');
        }

        return [
            'id' => $id,
            'name' => (string) data_get($payload, 'result.name', $name),
            'status' => (string) data_get($payload, 'result.status', 'inactive'),
            'cname' => $id.'.cfargotunnel.com',
        ];
    }

    public function tunnelToken(string $token, string $accountId, string $tunnelId): string
    {
        $response = $this->client($token)->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/token");
        $payload = $response->json();
        $this->assertSuccess($payload, 'Cloudflare could not issue a tunnel connector token.');
        $connectorToken = $payload['result'] ?? null;
        if (! is_string($connectorToken) || $connectorToken === '') {
            throw new RuntimeException('Cloudflare did not return a tunnel connector token.');
        }

        return $connectorToken;
    }

    /**
     * @param  array<int, array{hostname?: string, service: string}>  $ingress
     */
    public function putConfiguration(string $token, string $accountId, string $tunnelId, array $ingress): void
    {
        $response = $this->client($token)->put(
            "https://api.cloudflare.com/client/v4/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations",
            ['config' => ['ingress' => $ingress]],
        );
        $this->assertSuccess($response->json(), 'Cloudflare could not save the tunnel ingress configuration.');
    }

    public function createProxiedCnameIfMissing(string $token, string $zoneId, string $hostname, string $cname): void
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $cname = strtolower(rtrim($cname, '.'));
        $existing = $this->client($token)->get("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
            'name' => $hostname,
            'per_page' => 100,
        ]);
        $this->assertSuccess($existing->json(), 'Cloudflare DNS records could not be checked.');
        $records = collect($existing->json('result', []))->filter(fn ($record) => is_array($record));
        $matchingCname = $records->first(function (array $record) use ($cname): bool {
            return strtoupper((string) ($record['type'] ?? '')) === 'CNAME'
                && strtolower(rtrim((string) ($record['content'] ?? ''), '.')) === $cname;
        });
        if ($matchingCname !== null) {
            return;
        }
        if ($records->isNotEmpty()) {
            $first = $records->first();
            $type = (string) ($first['type'] ?? 'record');
            $content = (string) ($first['content'] ?? '');

            throw new RuntimeException("Cloudflare will not overwrite the existing {$type} record for {$hostname} ({$content}). Create a proxied CNAME to {$cname} only when the name is unused.");
        }

        $response = $this->client($token)->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
            'type' => 'CNAME',
            'name' => $hostname,
            'content' => $cname,
            'ttl' => 1,
            'proxied' => true,
        ]);
        $this->assertSuccess($response->json(), "Cloudflare could not create a CNAME for {$hostname}.");
    }

    public function tunnelStatus(string $token, string $accountId, string $tunnelId): string
    {
        $response = $this->client($token)->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/cfd_tunnel/{$tunnelId}");
        $payload = $response->json();
        $this->assertSuccess($payload, 'Cloudflare could not read the tunnel status.');

        return (string) data_get($payload, 'result.status', 'inactive');
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function assertSuccess(mixed $payload, string $fallback): void
    {
        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            $message = data_get($payload, 'errors.0.message');
            if (is_string($message) && $message !== '') {
                throw new RuntimeException($message);
            }

            throw new RuntimeException($fallback);
        }
    }
}
