<?php

namespace App\Services;

use App\Exceptions\RateLimitException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class DigitalOceanService
{
    private string $baseUrl = 'https://api.digitalocean.com/v2';

    public function __construct(private string $token) {}

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(3, function (int $attempt, \Exception $exception) {
                if ($exception instanceof RequestException && $exception->response?->status() === 429) {
                    $resetTime = $exception->response->header('RateLimit-Reset');

                    if ($resetTime) {
                        return min(max(0, (int) $resetTime - time()), 60) * 1000;
                    }
                }

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

            throw new \Exception('DigitalOcean API error: '.$response->json('message', 'Unknown error'), $response->status());
        }

        return $response->json() ?? [];
    }

    private function requestPaginated(string $endpoint, string $resourceKey, array $data = []): array
    {
        $allResults = [];
        $page = 1;

        do {
            $response = $this->request('get', $endpoint, array_merge($data, [
                'page' => $page,
                'per_page' => 50,
            ]));

            if (isset($response[$resourceKey])) {
                $allResults = array_merge($allResults, $response[$resourceKey]);
            }

            $hasNextPage = filled(data_get($response, 'links.pages.next'));
            $page++;
        } while ($hasNextPage);

        return $allResults;
    }

    public function getAccount(): array
    {
        return $this->request('get', '/account')['account'] ?? [];
    }

    public function getRegions(): array
    {
        return array_values(array_filter(
            $this->requestPaginated('/regions', 'regions'),
            fn (array $region) => ($region['available'] ?? true) === true
        ));
    }

    public function getSizes(): array
    {
        return array_values(array_filter(
            $this->requestPaginated('/sizes', 'sizes'),
            fn (array $size) => ($size['available'] ?? true) === true
        ));
    }

    public function getImages(): array
    {
        return array_values(array_filter(
            $this->requestPaginated('/images', 'images', ['type' => 'distribution']),
            fn (array $image) => ($image['public'] ?? true) === true
        ));
    }

    public function getSshKeys(): array
    {
        return $this->requestPaginated('/account/keys', 'ssh_keys');
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/account/keys', [
            'name' => $name,
            'public_key' => $publicKey,
        ]);

        return $response['ssh_key'] ?? [];
    }

    public function createDroplet(array $params): array
    {
        $response = $this->request('post', '/droplets', $params);

        return $response['droplet'] ?? [];
    }

    public function getDroplet(int $dropletId): array
    {
        $response = $this->request('get', $this->dropletEndpoint($dropletId));

        return $response['droplet'] ?? [];
    }

    public function waitForPublicIp(array $droplet, bool $enableIpv4 = true, bool $enableIpv6 = true, int $attempts = 30, int $sleepMilliseconds = 1000): array
    {
        if ($this->getPublicIpAddress($droplet, $enableIpv4, $enableIpv6) || empty($droplet['id'])) {
            return $droplet;
        }

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            usleep($sleepMilliseconds * 1000);

            $droplet = $this->getDroplet((int) $droplet['id']);

            if ($this->getPublicIpAddress($droplet, $enableIpv4, $enableIpv6)) {
                return $droplet;
            }
        }

        return $droplet;
    }

    public function powerOnDroplet(int $dropletId): array
    {
        $response = $this->request('post', $this->dropletEndpoint($dropletId).'/actions', [
            'type' => 'power_on',
        ]);

        return $response['action'] ?? [];
    }

    public function deleteDroplet(int $dropletId): void
    {
        $this->request('delete', $this->dropletEndpoint($dropletId));
    }

    public function getDroplets(): array
    {
        return $this->requestPaginated('/droplets', 'droplets');
    }

    public function findDropletByIp(string $ip): ?array
    {
        foreach ($this->getDroplets() as $droplet) {
            if ($this->dropletHasIp($droplet, $ip)) {
                return $droplet;
            }
        }

        return null;
    }

    public function getPublicIpAddress(array $droplet, bool $enableIpv4 = true, bool $enableIpv6 = true): ?string
    {
        if ($enableIpv4) {
            foreach (data_get($droplet, 'networks.v4', []) as $network) {
                if (($network['type'] ?? null) === 'public' && filled($network['ip_address'] ?? null)) {
                    return $network['ip_address'];
                }
            }
        }

        if ($enableIpv6) {
            foreach (data_get($droplet, 'networks.v6', []) as $network) {
                if (($network['type'] ?? null) === 'public' && filled($network['ip_address'] ?? null)) {
                    return $network['ip_address'];
                }
            }
        }

        return null;
    }

    private function dropletEndpoint(int $dropletId): string
    {
        return '/droplets/'.$dropletId;
    }

    private function dropletHasIp(array $droplet, string $ip): bool
    {
        foreach (['v4', 'v6'] as $version) {
            foreach (data_get($droplet, "networks.{$version}", []) as $network) {
                if (($network['ip_address'] ?? null) === $ip) {
                    return true;
                }
            }
        }

        return false;
    }
}
