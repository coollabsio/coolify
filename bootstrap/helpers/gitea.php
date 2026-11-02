<?php

use App\Models\GiteaApp;
use App\Models\PrivateKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Gitea caps page sizes at its MAX_RESPONSE_ITEMS setting (default 50).
 */
const GITEA_PAGE_SIZE = 50;

function refreshGiteaToken(GiteaApp $source): void
{
    if (! $source->refresh_token) {
        throw new RuntimeException('Gitea source has no refresh token. Please reconnect the Gitea app.');
    }

    $safetyMargin = 60;
    if ($source->expires_at && $source->expires_at > time() + $safetyMargin) {
        return;
    }

    $lock = Cache::lock("gitea_token_refresh_{$source->id}", 20);
    if (! $lock->block(20)) {
        $source->refresh();
        if ($source->expires_at && $source->expires_at > time() + $safetyMargin) {
            return;
        }
        throw new RuntimeException('Gitea token refresh timed out. Please try again.');
    }

    try {
        $source->refresh();
        if ($source->expires_at && $source->expires_at > time() + $safetyMargin) {
            return;
        }

        $baseUrl = rtrim($source->html_url, '/');

        $response = Http::GitSource($baseUrl)->asForm()->post("{$baseUrl}/login/oauth/access_token", [
            'client_id' => $source->client_id,
            'client_secret' => $source->client_secret,
            'refresh_token' => $source->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $error = data_get($response->json(), 'error_description', $response->body());
            throw new RuntimeException("Failed to refresh Gitea token: {$error}");
        }

        $data = $response->json();
        $source->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $source->refresh_token,
            'expires_at' => time() + ($data['expires_in'] ?? 3600),
        ]);
    } finally {
        $lock->release();
    }
}

function giteaApi(GiteaApp $source, string $endpoint, string $method = 'get', ?array $data = null): array
{
    refreshGiteaToken($source);

    $client = Http::Gitea($source->apiUrlBase(), $source->access_token)
        ->timeout(20)
        ->retry(3, 200, throw: false);

    if ($data && in_array(strtolower($method), ['post', 'patch', 'put'])) {
        $response = $client->$method($endpoint, $data);
    } else {
        $response = $client->$method($endpoint);
    }

    if (! $response->successful()) {
        $errorMessage = data_get($response->json(), 'message', $response->body());
        throw new RuntimeException("Gitea API call failed: {$errorMessage}");
    }

    return [
        'data' => collect($response->json()),
        'total' => (int) $response->header('x-total-count', 0),
    ];
}

function giteaAppPrivateKey(GiteaApp $source): ?PrivateKey
{
    $privateKey = $source->privateKey;

    return $privateKey?->team_id === $source->team_id ? $privateKey : null;
}

function generateGiteaCloneToken(GiteaApp $source): string
{
    refreshGiteaToken($source);

    return $source->access_token;
}

function loadGiteaRepositories(GiteaApp $source, int $page = 1): array
{
    refreshGiteaToken($source);

    $response = Http::Gitea($source->apiUrlBase(), $source->access_token)
        ->timeout(20)
        ->retry(3, 200, throw: false)
        ->get('/user/repos', [
            'limit' => GITEA_PAGE_SIZE,
            'page' => $page,
        ]);

    if (! $response->successful()) {
        return ['total_count' => 0, 'has_more' => false, 'repositories' => []];
    }

    $repositories = collect($response->json());
    $hasMore = count($response->json()) === GITEA_PAGE_SIZE;

    $organizations = collect(explode(',', (string) $source->organization))
        ->map(fn ($organization) => strtolower(trim($organization)))
        ->filter();
    if ($organizations->isNotEmpty()) {
        $repositories = $repositories->filter(
            fn ($repository) => $organizations->contains(strtolower((string) data_get($repository, 'owner.login')))
        );
    }

    return [
        'total_count' => $repositories->count(),
        'has_more' => $hasMore,
        'repositories' => $repositories->map(fn ($repository) => [
            'id' => data_get($repository, 'id'),
            'name' => data_get($repository, 'name'),
            'full_name' => data_get($repository, 'full_name'),
            'default_branch' => data_get($repository, 'default_branch', 'main'),
            'html_url' => data_get($repository, 'html_url'),
            'owner' => data_get($repository, 'owner.login'),
        ])->values()->all(),
    ];
}

function loadGiteaBranches(GiteaApp $source, string $fullName, int $page = 1): array
{
    refreshGiteaToken($source);

    $repositoryPath = collect(explode('/', $fullName, 2))->map(fn ($part) => rawurlencode($part))->implode('/');
    $response = Http::Gitea($source->apiUrlBase(), $source->access_token)
        ->timeout(20)
        ->retry(3, 200, throw: false)
        ->get("/repos/{$repositoryPath}/branches", [
            'limit' => GITEA_PAGE_SIZE,
            'page' => $page,
        ]);

    if (! $response->successful()) {
        return [];
    }

    return $response->json();
}
