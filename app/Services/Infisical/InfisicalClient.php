<?php

namespace App\Services\Infisical;

use App\Models\InfisicalConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class InfisicalClient
{
    private const TIMEOUT_SECONDS = 15;

    private ?string $accessToken = null;

    public function __construct(private readonly InfisicalConnection $connection) {}

    /**
     * @throws InfisicalApiException
     */
    public function fetchSecrets(string $projectId, string $environmentSlug, string $secretPath): FetchedSecrets
    {
        $response = $this->send('get', '/api/v3/secrets/raw', [
            'workspaceId' => $projectId,
            'environment' => $environmentSlug,
            'secretPath' => $secretPath,
        ], 'fetch secrets');

        $values = [];
        $hiddenKeys = [];

        foreach ($response->json('secrets', []) as $secret) {
            $key = data_get($secret, 'secretKey');
            if ($key === null) {
                continue;
            }

            if (data_get($secret, 'secretValueHidden')) {
                $hiddenKeys[] = $key;

                continue;
            }

            $values[$key] = (string) data_get($secret, 'secretValue', '');
        }

        return new FetchedSecrets($values, $hiddenKeys);
    }

    /**
     * Infisical exposes no list-environments route; they live on the project
     * object. Verified against a live self-hosted instance: the route is
     * /api/v1/workspace/{id} returning {"workspace": {... "environments": []}}.
     * /api/v1/projects/{id} does NOT exist, and /api/v2/workspace/{x} takes a
     * SLUG rather than an id.
     *
     * @return array<int, string>
     *
     * @throws InfisicalApiException
     */
    public function listEnvironmentSlugs(string $projectId): array
    {
        $response = $this->send('get', "/api/v1/workspace/{$projectId}", [], 'list environments');

        return collect($response->json('workspace.environments', []))
            ->pluck('slug')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Returns false when Infisical refuses — insufficient project role, or the
     * organisation's environment limit. Callers degrade to skip-and-warn
     * rather than failing the whole sync.
     *
     * @throws InfisicalApiException
     */
    public function createEnvironment(string $projectId, string $name, string $slug): bool
    {
        $response = $this->raw('post', "/api/v1/workspace/{$projectId}/environments", [
            'name' => $name,
            'slug' => $slug,
        ]);

        if ($response->status() === 403 || $response->status() === 402) {
            return false;
        }

        $this->throwUnlessSuccessful($response, 'create environment');

        return true;
    }

    /**
     * @return array<int, string>
     *
     * @throws InfisicalApiException
     */
    public function listFolderNames(string $projectId, string $environmentSlug, string $path): array
    {
        $response = $this->send('get', '/api/v1/folders', [
            'workspaceId' => $projectId,
            'environment' => $environmentSlug,
            'path' => $path,
        ], 'list folders');

        return collect($response->json('folders', []))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @throws InfisicalApiException
     */
    public function createFolder(string $projectId, string $environmentSlug, string $path, string $name): void
    {
        $response = $this->raw('post', '/api/v1/folders', [
            'workspaceId' => $projectId,
            'environment' => $environmentSlug,
            'path' => $path,
            'name' => $name,
        ]);

        // A concurrent sync may have created it between our list and our create.
        if ($response->status() === 409) {
            return;
        }

        $this->throwUnlessSuccessful($response, 'create folder');
    }

    /**
     * Walks the path one level at a time. The Infisical API does not document
     * recursive parent creation, so it must not be assumed.
     *
     * @throws InfisicalApiException
     */
    public function ensureFolderPath(string $projectId, string $environmentSlug, string $path): void
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        $parent = '/';
        foreach ($segments as $segment) {
            $existing = $this->listFolderNames($projectId, $environmentSlug, $parent);

            if (! in_array($segment, $existing, true)) {
                $this->createFolder($projectId, $environmentSlug, $parent, $segment);
            }

            $parent = $parent === '/' ? "/{$segment}" : "{$parent}/{$segment}";
        }
    }

    /**
     * One batch call with mode=upsert — creates what is missing, updates what
     * exists, no create-vs-update branching.
     *
     * Verified against a live self-hosted instance: the route is
     * PATCH /api/v3/secrets/batch/raw taking workspaceId. /api/v4/secrets/batch
     * exists on Infisical Cloud but NOT on the self-hosted image, and folders
     * are /api/v1/folders with workspaceId rather than /api/v2 with projectId.
     *
     * @param  array<string, string>  $secrets
     *
     * @throws InfisicalApiException
     */
    public function upsertSecrets(string $projectId, string $environmentSlug, string $secretPath, array $secrets): void
    {
        if ($secrets === []) {
            return;
        }

        $payload = [];
        foreach ($secrets as $key => $value) {
            $payload[] = ['secretKey' => (string) $key, 'secretValue' => (string) $value];
        }

        $this->send('patch', '/api/v3/secrets/batch/raw', [
            'workspaceId' => $projectId,
            'environment' => $environmentSlug,
            'secretPath' => $secretPath,
            'mode' => 'upsert',
            'secrets' => $payload,
        ], 'upsert secrets');
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InfisicalApiException
     */
    private function send(string $method, string $path, array $payload, string $describe): Response
    {
        $response = $this->raw($method, $path, $payload);

        $this->throwUnlessSuccessful($response, $describe);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InfisicalApiException
     */
    private function raw(string $method, string $path, array $payload): Response
    {
        try {
            return $this->request()->{$method}($this->connection->host.$path, $payload);
        } catch (ConnectionException $e) {
            throw new InfisicalApiException(
                "Could not connect to Infisical host {$this->connection->host}: {$e->getMessage()}"
            );
        }
    }

    /**
     * @throws InfisicalApiException
     */
    private function throwUnlessSuccessful(Response $response, string $describe): void
    {
        if ($response->failed()) {
            throw new InfisicalApiException(
                "Infisical request to {$describe} failed with status {$response->status()}."
            );
        }
    }

    /**
     * @throws InfisicalApiException
     */
    private function request(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)->withToken($this->accessToken());
    }

    /**
     * Universal Auth. The token is held in memory for the lifetime of this
     * instance only and is never persisted.
     *
     * @throws InfisicalApiException
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->post($this->connection->host.'/api/v1/auth/universal-auth/login', [
                    'clientId' => $this->connection->client_id,
                    'clientSecret' => $this->connection->client_secret,
                ]);
        } catch (ConnectionException $e) {
            throw new InfisicalApiException(
                "Could not connect to Infisical host {$this->connection->host} to authenticate: {$e->getMessage()}"
            );
        }

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
