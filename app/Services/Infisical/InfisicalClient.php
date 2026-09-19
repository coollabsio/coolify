<?php

namespace App\Services\Infisical;

use App\Models\InfisicalConnection;
use Illuminate\Support\Facades\Http;

class InfisicalClient
{
    private const TIMEOUT_SECONDS = 15;

    private ?string $accessToken = null;

    public function __construct(private readonly InfisicalConnection $connection) {}

    /**
     * Fetch every secret at the given path as a key => value map.
     *
     * @return array<string, string>
     *
     * @throws InfisicalApiException
     */
    public function fetchSecrets(string $projectId, string $environmentSlug, string $secretPath): array
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withToken($this->accessToken())
            ->get($this->connection->host.'/api/v3/secrets/raw', [
                'workspaceId' => $projectId,
                'environment' => $environmentSlug,
                'secretPath' => $secretPath,
            ]);

        if ($response->failed()) {
            throw new InfisicalApiException(
                "Infisical secret fetch failed with status {$response->status()}."
            );
        }

        $secrets = [];
        foreach ($response->json('secrets', []) as $secret) {
            $key = data_get($secret, 'secretKey');
            if ($key === null) {
                continue;
            }
            $secrets[$key] = (string) data_get($secret, 'secretValue', '');
        }

        return $secrets;
    }

    /**
     * Authenticate with Universal Auth. The token is held in memory for the
     * lifetime of this instance only and is never persisted.
     *
     * @throws InfisicalApiException
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->post($this->connection->host.'/api/v1/auth/universal-auth/login', [
                'clientId' => $this->connection->client_id,
                'clientSecret' => $this->connection->client_secret,
            ]);

        if ($response->failed()) {
            throw new InfisicalApiException(
                "Infisical authentication failed with status {$response->status()}."
            );
        }

        $token = $response->json('accessToken');
        if (! is_string($token) || $token === '') {
            throw new InfisicalApiException('Infisical authentication returned no access token.');
        }

        return $this->accessToken = $token;
    }
}
