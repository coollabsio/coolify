<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class VaultService
{
    private string $baseUrl;

    public function __construct(string $baseUrl, private string $token, private ?string $namespace = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function validate(): bool
    {
        try {
            return $this->client()->get($this->baseUrl.'/v1/auth/token/lookup-self')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Read a KV v2 secret. Non-string values are stored as JSON strings.
     *
     * @return array<string, string>
     */
    public function fetchSecrets(string $mount, string $path): array
    {
        $mount = trim($mount, '/');
        $path = trim($path, '/');

        $response = $this->client()->get($this->baseUrl."/v1/{$mount}/data/{$path}");

        if (! $response->successful()) {
            throw new \RuntimeException('Vault API error: '.($response->json('errors.0') ?? 'HTTP '.$response->status()));
        }

        return collect($response->json('data.data', []))
            ->map(fn ($value) => is_string($value) ? $value : json_encode($value))
            ->all();
    }

    private function client(): PendingRequest
    {
        $client = Http::withHeaders(['X-Vault-Token' => $this->token])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(10);

        if (filled($this->namespace)) {
            $client = $client->withHeaders(['X-Vault-Namespace' => $this->namespace]);
        }

        return $client;
    }
}
