<?php

namespace App\Services;

use App\Exceptions\RateLimitException;
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
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
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
            })
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

            throw new \Exception('Hetzner API error: '.$response->json('error.message', 'Unknown error'));
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

    public function getServerTypes(): array
    {
        $types = $this->requestPaginated('get', '/server_types', 'server_types');

        // Filter out entries where "deprecated" is explicitly true
        $filtered = array_filter($types, function ($type) {
            return ! (isset($type['deprecated']) && $type['deprecated'] === true);
        });

        return array_values($filtered);
    }

    public function getSshKeys(): array
    {
        return $this->requestPaginated('get', '/ssh_keys', 'ssh_keys');
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
        ray('Hetzner createServer request', [
            'endpoint' => '/servers',
            'params' => $params,
        ]);

        $response = $this->request('post', '/servers', $params);

        ray('Hetzner createServer response', [
            'response' => $response,
        ]);

        return $response['server'] ?? [];
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
        $servers = $this->getServers();

        foreach ($servers as $server) {
            // Check IPv4
            $ipv4 = data_get($server, 'public_net.ipv4.ip');
            if ($ipv4 === $ip) {
                return $server;
            }

            // Check IPv6 (Hetzner returns the full /64 block)
            $ipv6 = data_get($server, 'public_net.ipv6.ip');
            if ($ipv6 && str_starts_with($ip, rtrim($ipv6, '/'))) {
                return $server;
            }
        }

        return null;
    }
}
