<?php

namespace App\Services;

use App\Rules\SafeExternalUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class InfisicalService
{
    private string $baseUrl;

    /** @var array<string, mixed> */
    private array $httpClientOptions;

    public function __construct(string $baseUrl, private string $clientId, private string $clientSecret, private ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        Validator::make(['base_url' => $this->baseUrl], ['base_url' => new SafeExternalUrl])->validate();
        $this->httpClientOptions = SafeExternalUrl::httpClientOptions($this->baseUrl);
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
        // Older Infisical versions do not expand ${KEY} references unless asked to.
        $valueOptions = ['expandSecretReferences' => 'true', 'viewSecretValue' => 'true'];

        $response = $client->get($this->baseUrl.'/api/v4/secrets', [
            'projectId' => $projectId,
            'environment' => $environment,
            'secretPath' => $secretPath,
            'includeImports' => 'true',
            ...$valueOptions,
        ]);

        // Older self-hosted instances only expose the v3 endpoint.
        if ($response->status() === 404) {
            $response = $client->get($this->baseUrl.'/api/v3/secrets/raw', [
                'workspaceId' => $projectId,
                'environment' => $environment,
                'secretPath' => $secretPath,
                'include_imports' => 'true',
                ...$valueOptions,
            ]);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Infisical API error: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        // Like `infisical export`: later imports override earlier ones, and the folder's own secrets override all imports.
        return collect($response->json('imports', []))
            ->pluck('secrets')
            ->push($response->json('secrets', []))
            ->reduce(fn (array $merged, ?array $secrets) => array_replace($merged, $this->keyValues($secrets ?? [])), []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $secrets
     * @return array<string, string>
     */
    private function keyValues(array $secrets): array
    {
        return collect($secrets)
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
            ->withOptions($this->httpClientOptions)
            ->connectTimeout($this->timeout ?? 5)
            ->timeout($this->timeout ?? 10);
    }
}
