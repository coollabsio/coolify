<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DopplerService
{
    private string $baseUrl = 'https://api.doppler.com';

    public function __construct(private string $token) {}

    public function validate(): bool
    {
        try {
            return $this->client()->get($this->baseUrl.'/v3/me')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Download all secrets for a config. Project and config are not needed for
     * service tokens (the token itself is pinned to one config).
     *
     * @return array<string, string>
     */
    public function fetchSecrets(?string $project = null, ?string $config = null): array
    {
        $query = ['format' => 'json'];
        if (filled($project)) {
            $query['project'] = $project;
        }
        if (filled($config)) {
            $query['config'] = $config;
        }

        $response = $this->client()->get($this->baseUrl.'/v3/configs/config/secrets/download', $query);

        if (! $response->successful()) {
            throw new \RuntimeException('Doppler API error: '.($response->json('messages.0') ?? 'HTTP '.$response->status()));
        }

        return collect($response->json())
            ->map(fn ($value) => is_string($value) ? $value : json_encode($value))
            ->all();
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(10);
    }
}
