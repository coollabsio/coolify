<?php

namespace App\Services;

use App\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Http;

class VultrService
{
    private string $baseUrl = 'https://api.vultr.com/v2';

    public function __construct(private string $token) {}

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->timeout(30)
            ->retry(3, fn (int $attempt) => $attempt * 100, throw: false)
            ->{$method}($this->baseUrl.$endpoint, $data);

        if (! $response->successful()) {
            if ($response->status() === 429) {
                throw new RateLimitException(
                    'Rate limit exceeded. Please try again later.',
                    $response->header('Retry-After') !== null ? (int) $response->header('Retry-After') : null
                );
            }

            throw new \Exception('Vultr API error: '.$response->json('error', 'Unknown error'), $response->status());
        }

        return $response->json() ?? [];
    }

    private function requestPaginated(string $endpoint, string $resourceKey, array $data = []): array
    {
        $allResults = [];
        $cursor = null;

        do {
            $query = $data;
            $query['per_page'] = 100;

            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }

            $response = $this->request('get', $endpoint, $query);

            if (isset($response[$resourceKey])) {
                $allResults = array_merge($allResults, $response[$resourceKey]);
            }

            $next = $response['meta']['links']['next'] ?? null;
            $cursor = $this->cursorFromNextLink($next);
        } while ($cursor !== null);

        return $allResults;
    }

    private function cursorFromNextLink(?string $next): ?string
    {
        if (blank($next)) {
            return null;
        }

        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);

        return $query['cursor'] ?? null;
    }

    public function getRegions(): array
    {
        return $this->requestPaginated('/regions', 'regions');
    }

    public function getPlans(): array
    {
        return $this->requestPaginated('/plans', 'plans');
    }

    public function getOperatingSystems(): array
    {
        return $this->requestPaginated('/os', 'os');
    }

    public function getSshKeys(): array
    {
        return $this->requestPaginated('/ssh-keys', 'ssh_keys');
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/ssh-keys', [
            'name' => $name,
            'ssh_key' => $publicKey,
        ]);

        return $response['ssh_key'] ?? [];
    }

    public function createInstance(array $params): array
    {
        if (! empty($params['user_data'])) {
            $params['user_data'] = base64_encode($params['user_data']);
        }

        $response = $this->request('post', '/instances', $params);

        return $response['instance'] ?? [];
    }

    public function waitForPublicIp(array $instance, bool $disablePublicIpv4 = false, bool $enableIpv6 = true, int $attempts = 6, int $sleepMilliseconds = 1000): array
    {
        if ($this->getPublicIp($instance, $disablePublicIpv4, $enableIpv6) || empty($instance['id'])) {
            return $instance;
        }

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            usleep($sleepMilliseconds * 1000);

            $instance = $this->getInstance($instance['id']);

            if ($this->getPublicIp($instance, $disablePublicIpv4, $enableIpv6)) {
                return $instance;
            }
        }

        return $instance;
    }

    public function getPublicIp(array $instance, bool $disablePublicIpv4 = false, bool $enableIpv6 = true): ?string
    {
        $ipv4 = $instance['main_ip'] ?? null;
        if (! $disablePublicIpv4 && $this->isUsableIp($ipv4)) {
            return $ipv4;
        }

        $ipv6 = $instance['v6_main_ip'] ?? null;
        if ($enableIpv6 && $this->isUsableIp($ipv6)) {
            return $ipv6;
        }

        return null;
    }

    private function isUsableIp(?string $ip): bool
    {
        return ! blank($ip) && ! in_array($ip, ['0.0.0.0', '::'], true);
    }

    public function getInstance(string $instanceId): array
    {
        $response = $this->request('get', $this->instanceEndpoint($instanceId));

        return $response['instance'] ?? [];
    }

    public function startInstance(string $instanceId): array
    {
        return $this->request('post', $this->instanceEndpoint($instanceId).'/start');
    }

    public function deleteInstance(string $instanceId): void
    {
        $this->request('delete', $this->instanceEndpoint($instanceId));
    }

    public function getInstances(): array
    {
        return $this->requestPaginated('/instances', 'instances');
    }

    public function findInstanceByIp(string $ip): ?array
    {
        foreach ($this->getInstances() as $instance) {
            if (($instance['main_ip'] ?? null) === $ip || ($instance['v6_main_ip'] ?? null) === $ip) {
                return $instance;
            }
        }

        return null;
    }

    private function instanceEndpoint(string $instanceId): string
    {
        return '/instances/'.rawurlencode($instanceId);
    }
}
