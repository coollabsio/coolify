<?php

use App\Models\GitlabApp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function refreshGitlabToken(GitlabApp $source): void
{
    if (! $source->refresh_token) {
        throw new RuntimeException('GitLab source has no refresh token. Please reconnect the GitLab app.');
    }

    $safetyMargin = 60;
    if ($source->expires_at && $source->expires_at > time() + $safetyMargin) {
        return;
    }

    $lock = Cache::lock("gitlab_token_refresh_{$source->id}", 20);
    if (! $lock->block(20)) {
        $source->refresh();
        if ($source->expires_at && $source->expires_at > time() + $safetyMargin) {
            return;
        }
        throw new RuntimeException('GitLab token refresh timed out. Please try again.');
    }

    try {
        $source->refresh();
        if ($source->expires_at && $source->expires_at > time() + $safetyMargin) {
            return;
        }

        $baseUrl = rtrim($source->html_url, '/');

        $response = Http::asForm()->post("{$baseUrl}/oauth/token", [
            'client_id' => $source->client_id,
            'client_secret' => $source->client_secret,
            'refresh_token' => $source->refresh_token,
            'grant_type' => 'refresh_token',
            'redirect_uri' => $source->redirect_uri,
        ]);

        if (! $response->successful()) {
            $error = data_get($response->json(), 'error_description', $response->body());
            throw new RuntimeException("Failed to refresh GitLab token: {$error}");
        }

        $data = $response->json();
        $source->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => time() + ($data['expires_in'] ?? 7200),
        ]);
    } finally {
        $lock->release();
    }
}

function gitlabApi(GitlabApp $source, string $endpoint, string $method = 'get', ?array $data = null): array
{
    refreshGitlabToken($source);

    $apiUrl = $source->apiUrlBase();

    $client = Http::GitLab($apiUrl, $source->access_token)
        ->timeout(20)
        ->retry(3, 200, throw: false);

    if ($data && in_array(strtolower($method), ['post', 'patch', 'put'])) {
        $response = $client->$method($endpoint, $data);
    } else {
        $response = $client->$method($endpoint);
    }

    if (! $response->successful()) {
        $errorMessage = data_get($response->json(), 'message', $response->body());
        throw new RuntimeException("GitLab API call failed: {$errorMessage}");
    }

    return [
        'data' => collect($response->json()),
        'total' => (int) $response->header('x-total', 0),
    ];
}

function generateGitlabCloneToken(GitlabApp $source): string
{
    refreshGitlabToken($source);

    return $source->access_token;
}

function loadGitlabRepositories(GitlabApp $source, int $page = 1): array
{
    refreshGitlabToken($source);

    $apiUrl = $source->apiUrlBase();
    $response = Http::GitLab($apiUrl, $source->access_token)
        ->timeout(20)
        ->retry(3, 200, throw: false)
        ->get('/projects', [
            'membership' => 'true',
            'per_page' => 100,
            'page' => $page,
            'order_by' => 'name',
            'sort' => 'asc',
        ]);

    if (! $response->successful()) {
        return ['total_count' => 0, 'has_more' => false, 'repositories' => []];
    }

    $projects = collect($response->json());
    $rawCount = count($response->json());
    $hasMore = $rawCount === 100;

    $groupName = $source->group_name;
    if (! empty($groupName)) {
        $groups = collect(explode(',', $groupName))->map(fn ($g) => strtolower(trim($g)))->filter();
        $projects = $projects->filter(function ($project) use ($groups) {
            $namespacePath = strtolower(data_get($project, 'namespace.full_path', ''));

            return $groups->contains(fn ($group) => $namespacePath === $group || str_starts_with($namespacePath, $group.'/'));
        });
    }

    return [
        'total_count' => $projects->count(),
        'has_more' => $hasMore,
        'repositories' => $projects->map(fn ($project) => [
            'id' => data_get($project, 'id'),
            'name' => data_get($project, 'name'),
            'path_with_namespace' => data_get($project, 'path_with_namespace'),
            'default_branch' => data_get($project, 'default_branch', 'main'),
            'web_url' => data_get($project, 'web_url'),
            'namespace' => [
                'full_path' => data_get($project, 'namespace.full_path'),
                'kind' => data_get($project, 'namespace.kind'),
            ],
        ])->values()->all(),
    ];
}

function loadGitlabBranches(GitlabApp $source, int $projectId, int $page = 1): array
{
    refreshGitlabToken($source);

    $apiUrl = $source->apiUrlBase();
    $response = Http::GitLab($apiUrl, $source->access_token)
        ->timeout(20)
        ->retry(3, 200, throw: false)
        ->get("/projects/{$projectId}/repository/branches", [
            'per_page' => 100,
            'page' => $page,
        ]);

    if (! $response->successful()) {
        return [];
    }

    return $response->json();
}

/**
 * Public URL GitLab must call for the push / merge request events of this source.
 *
 * The base is taken from the OAuth redirect URI the user already validated against GitLab,
 * so the webhook lands on the same public endpoint (tunnel, reverse proxy, custom domain).
 */
function gitlabWebhookUrl(GitlabApp $source): string
{
    $base = filled($source->redirect_uri)
        ? rtrim(str($source->redirect_uri)->before('/webhooks/source/gitlab/redirect')->toString(), '/')
        : '';

    if (blank($base)) {
        $base = rtrim((string) config('app.url'), '/');
    }

    if (blank($base)) {
        throw new RuntimeException('Coolify has no public URL configured. Set the instance FQDN before configuring GitLab webhooks.');
    }

    return $base.'/webhooks/source/gitlab/events';
}

/**
 * Find the Coolify webhook already registered on a GitLab project.
 *
 * @return array{id: int, url: string}|null
 */
function findGitlabProjectWebhook(GitlabApp $source, int $projectId, ?string $url = null): ?array
{
    $url ??= gitlabWebhookUrl($source);

    $hooks = gitlabApi($source, "/projects/{$projectId}/hooks?per_page=100")['data'];

    $hook = $hooks->first(
        fn ($hook): bool => rtrim((string) data_get($hook, 'url'), '/') === rtrim($url, '/')
    );

    if (! $hook) {
        return null;
    }

    return [
        'id' => (int) data_get($hook, 'id'),
        'url' => (string) data_get($hook, 'url'),
    ];
}

/**
 * Create (or refresh) the Coolify webhook on a GitLab project so pushes deploy automatically.
 *
 * GitLab OAuth applications have no app-level webhook like GitHub Apps do, so Coolify has to
 * register a project hook itself. Requires the Maintainer role on the project.
 *
 * @return array{status: string, id: int, url: string}
 */
function syncGitlabProjectWebhook(GitlabApp $source, int $projectId): array
{
    if (! $source->isConnected()) {
        throw new RuntimeException('This GitLab source is not connected. Authorize it before configuring webhooks.');
    }

    $token = (string) $source->webhook_token;
    if (blank($token)) {
        throw new RuntimeException('This GitLab source has no webhook token. Save the source again to generate one.');
    }

    $url = gitlabWebhookUrl($source);

    $payload = [
        'url' => $url,
        'token' => $token,
        'push_events' => true,
        'merge_requests_events' => true,
        'enable_ssl_verification' => str_starts_with($url, 'https://'),
    ];

    $existing = findGitlabProjectWebhook($source, $projectId, $url);

    if ($existing) {
        gitlabApi($source, "/projects/{$projectId}/hooks/{$existing['id']}", 'put', $payload);

        return ['status' => 'updated', 'id' => $existing['id'], 'url' => $url];
    }

    $created = gitlabApi($source, "/projects/{$projectId}/hooks", 'post', $payload)['data'];

    return ['status' => 'created', 'id' => (int) data_get($created, 'id'), 'url' => $url];
}

/**
 * Remove the Coolify webhook from a GitLab project. Returns false when there was nothing to remove.
 */
function removeGitlabProjectWebhook(GitlabApp $source, int $projectId): bool
{
    $existing = findGitlabProjectWebhook($source, $projectId);

    if (! $existing) {
        return false;
    }

    gitlabApi($source, "/projects/{$projectId}/hooks/{$existing['id']}", 'delete');

    return true;
}
