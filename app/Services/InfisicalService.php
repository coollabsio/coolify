<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class InfisicalService
{
    private string $baseUrl;

    public function __construct(string $baseUrl, private string $clientId, private string $clientSecret)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function validate(): bool
    {
        try {
            $this->login();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public function fetchSecrets(string $projectId, string $environment, string $secretPath = '/'): array
    {
        $client = $this->client()->withToken($this->login());
        $secretPath = $secretPath ?: '/';

        $response = $client->get($this->baseUrl.'/api/v4/secrets', [
            'projectId' => $projectId,
            'environment' => $environment,
            'secretPath' => $secretPath,
        ]);

        // Older self-hosted instances only expose the v3 endpoint.
        if ($response->status() === 404) {
            $response = $client->get($this->baseUrl.'/api/v3/secrets/raw', [
                'workspaceId' => $projectId,
                'environment' => $environment,
                'secretPath' => $secretPath,
            ]);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Infisical API error: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return collect($response->json('secrets', []))
            ->mapWithKeys(fn ($secret) => [(string) data_get($secret, 'secretKey') => (string) data_get($secret, 'secretValue', '')])
            ->all();
    }

    private function login(): string
    {
        $response = $this->client()->post($this->baseUrl.'/api/v1/auth/universal-auth/login', [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
        ]);

        $accessToken = $response->json('accessToken');
        if (! $response->successful() || blank($accessToken)) {
            throw new \RuntimeException('Infisical login failed: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return $accessToken;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(10);
    }
}
