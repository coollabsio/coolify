<?php

namespace App\Services;

use App\Exceptions\CloudProviderApiException;
use App\Exceptions\RateLimitException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class HetznerService
{
    private string $token;

    private string $baseUrl = 'https://api.hetzner.cloud/v1';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    private function request(string $method, string $endpoint, array $data = [])
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->timeout(30)
            ->retry(3, function (int $attempt, \Exception $exception) {
                // Handle rate limiting (429 Too Many Requests)
                if ($exception instanceof RequestException) {
                    $response = $exception->response;

                    if ($response && $response->status() === 429) {
                        // Get rate limit reset timestamp from headers
                        $resetTime = $response->header('RateLimit-Reset');

                        if ($resetTime) {
                            // Calculate wait time until rate limit resets
                            $waitSeconds = max(0, $resetTime - time());

                            // Cap wait time at 60 seconds for safety
                            return min($waitSeconds, 60) * 1000;
                        }
                    }
                }

                // Exponential backoff for other retriable errors: 100ms, 200ms, 400ms
                return $attempt * 100;
            }, function (\Exception $exception) use ($method) {
                if ($exception instanceof RequestException && $exception->response->status() === 429) {
                    return true;
                }

                // A POST that failed with a 5xx or a timeout can still have created the resource, so only idempotent requests are sent again.
                // Client errors such as 401, 403, or 422 do not change when the request is sent again.
                $isServerError = ! $exception instanceof RequestException || $exception->response->status() >= 500;

                return $isServerError && in_array(strtolower($method), ['get', 'delete'], true);
            }, throw: false)
            ->{$method}($this->baseUrl.$endpoint, $data);

        if (! $response->successful()) {
            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');
                if ($retryAfter === null) {
                    $resetTime = $response->header('RateLimit-Reset');
                    $retryAfter = $resetTime ? max(0, (int) $resetTime - time()) : null;
                }

                throw new RateLimitException(
                    'Rate limit exceeded. Please try again later.',
                    $retryAfter !== null ? (int) $retryAfter : null
                );
            }

            throw new CloudProviderApiException(
                'Hetzner',
                $response->json('error.message', 'Unknown error'),
                $response->status(),
                $response->json('error.code'),
                collect($response->json('error.details.fields', []))->pluck('name')->all(),
            );
        }

        return $response->json();
    }

    private function requestPaginated(string $method, string $endpoint, string $resourceKey, array $data = []): array
    {
        $allResults = [];
        $page = 1;

        do {
            $data['page'] = $page;
            $data['per_page'] = 50;

            $response = $this->request($method, $endpoint, $data);

            if (isset($response[$resourceKey])) {
                $allResults = array_merge($allResults, $response[$resourceKey]);
            }

            $nextPage = $response['meta']['pagination']['next_page'] ?? null;
            $page = $nextPage;
        } while ($nextPage !== null);

        return $allResults;
    }

    public function getLocations(): array
    {
        return $this->requestPaginated('get', '/locations', 'locations');
    }

    public function getImages(): array
    {
        return $this->requestPaginated('get', '/images', 'images', [
            'type' => 'system',
        ]);
    }

    /**
     * Server types that can be ordered now. Each type keeps only the locations
     * where it is in stock and not deprecated, and types with no such location are removed.
     * With $includeOutOfStock, locations where the type is out of stock are kept.
     */
    public function getServerTypes(?string $location = null, bool $includeOutOfStock = false): array
    {
        $types = $this->requestPaginated('get', '/server_types', 'server_types');

        return collect($types)
            ->reject(fn (array $type) => ($type['deprecated'] ?? false) === true)
            ->map(function (array $type) use ($includeOutOfStock) {
                $type['locations'] = collect($type['locations'] ?? [])
                    ->filter(fn (array $typeLocation) => empty($typeLocation['deprecation'])
                        && ($includeOutOfStock || ($typeLocation['available'] ?? true) === true))
                    ->values()
                    ->all();

                return $type;
            })
            ->filter(fn (array $type) => $location === null
                ? $type['locations'] !== []
                : in_array($location, array_column($type['locations'], 'name'), true))
            ->values()
            ->all();
    }

    public function getSshKeys(): array
    {
        return $this->requestPaginated('get', '/ssh_keys', 'ssh_keys');
    }

    public function getFirewalls(): array
    {
        return $this->requestPaginated('get', '/firewalls', 'firewalls');
    }

    public function getNetworks(): array
    {
        return $this->requestPaginated('get', '/networks', 'networks');
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/ssh_keys', [
            'name' => $name,
            'public_key' => $publicKey,
        ]);

        return $response['ssh_key'] ?? [];
    }

    public function createServer(array $params): array
    {

        $response = $this->request('post', '/servers', $params);

        return $response['server'] ?? [];
    }

    public function enableServerBackup(int $serverId): array
    {
        $response = $this->request('post', "/servers/{$serverId}/actions/enable_backup");

        return $response['action'] ?? [];
    }

    public function getServer(int $serverId): array
    {
        $response = $this->request('get', "/servers/{$serverId}");

        return $response['server'] ?? [];
    }

    public function powerOnServer(int $serverId): array
    {
        $response = $this->request('post', "/servers/{$serverId}/actions/poweron");

        return $response['action'] ?? [];
    }

    public function deleteServer(int $serverId): void
    {
        $this->request('delete', "/servers/{$serverId}");
    }

    public function getServers(): array
    {
        return $this->requestPaginated('get', '/servers', 'servers');
    }

    public function findServerByIp(string $ip): ?array
    {
        $ip = normalizeIpAddress($ip);
        $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $servers = $this->getServers();

        foreach ($servers as $server) {
            $ipv4 = data_get($server, 'public_net.ipv4.ip');
            if (filled($ipv4) && normalizeIpAddress($ipv4) === $ip) {
                return $server;
            }

            // Hetzner returns the IPv6 network (2a01:4f8:c016:bd23::/64), not the server address.
            $ipv6Network = data_get($server, 'public_net.ipv6.ip');
            if ($isIpv6 && filled($ipv6Network) && str_contains($ipv6Network, '/') && ipMatch($ip, [$ipv6Network])) {
                return $server;
            }
        }

        return null;
    }
}
