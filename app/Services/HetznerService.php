<?php

namespace App\Services;

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
        ])->timeout(30)->{$method}($this->baseUrl.$endpoint, $data);

        if (! $response->successful()) {
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
        return $this->requestPaginated('get', '/server_types', 'server_types');
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
        $response = $this->request('post', '/servers', $params);

        return $response['server'] ?? [];
    }

    public function deleteServer(int $serverId): void
    {
        $this->request('delete', "/servers/{$serverId}");
    }
}
