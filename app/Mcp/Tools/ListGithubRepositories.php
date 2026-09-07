<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\GithubApp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListGithubRepositories extends Tool
{
    protected string $name = 'list_github_repositories';

    protected string $description = 'List repositories via a team GitHub app (calls GitHub API). Prefer list_github_apps first. Soft-registered only when a GitHub app exists.';

    use BuildsResponse;
    use ResolvesTeam;

    public function shouldRegister(): bool
    {
        return GithubApp::query()->exists();
    }

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $appUuid = $request->get('github_app_uuid');
        if (! is_string($appUuid) || $appUuid === '') {
            return $this->mcpError($request, 'github_app_uuid argument is required.');
        }

        $githubApp = GithubApp::query()
            ->where('uuid', $appUuid)
            ->where(function ($q) use ($teamId) {
                $q->where('team_id', $teamId)->orWhere('is_system_wide', true);
            })
            ->first();

        if (! $githubApp) {
            return $this->mcpError($request, "GitHub app [{$appUuid}] not found.", ['resource_uuid' => $appUuid]);
        }

        // Public (anonymous) GitHub sources have no app credentials / installation.
        // Listing installation repositories requires a GitHub App installation token.
        if ($githubApp->is_public || blank($githubApp->app_id) || blank($githubApp->installation_id) || blank($githubApp->private_key_id)) {
            return $this->mcpError(
                $request,
                'This GitHub source is public or missing app installation credentials. list_github_repositories requires a configured GitHub App with installation access. Use list_github_apps to pick a non-public app, or list_github_branches with owner/repo for public repositories.',
                ['resource_uuid' => $appUuid],
            );
        }

        try {
            $token = generateGithubInstallationToken($githubApp);
            $repositories = collect();
            $page = 1;
            $maxPages = 20;

            while ($page <= $maxPages) {
                $response = Http::GitHub($githubApp->api_url, $token)
                    ->timeout(20)
                    ->get('/installation/repositories', ['per_page' => 100, 'page' => $page]);

                if ($response->failed()) {
                    $status = $response->status();
                    $hint = match (true) {
                        $status === 401, $status === 403 => 'GitHub app credentials/installation invalid or missing permissions.',
                        $status === 404 => 'GitHub installation or endpoint not found.',
                        default => 'GitHub API request failed.',
                    };

                    return $this->mcpError($request, "{$hint} (HTTP {$status})", ['resource_uuid' => $appUuid]);
                }

                $batch = collect($response->json('repositories') ?? []);
                if ($batch->isEmpty()) {
                    break;
                }

                $repositories = $repositories->merge($batch);
                if ($batch->count() < 100) {
                    break;
                }
                $page++;
            }

            $summaries = $repositories->map(fn ($repo) => [
                'id' => data_get($repo, 'id'),
                'name' => data_get($repo, 'name'),
                'full_name' => data_get($repo, 'full_name'),
                'private' => data_get($repo, 'private'),
                'html_url' => data_get($repo, 'html_url'),
                'default_branch' => data_get($repo, 'default_branch'),
            ])->values()->all();

            return $this->mcpSuccess($request, $this->respond([
                'github_app_uuid' => $appUuid,
                'repositories' => $summaries,
            ]), ['resource_uuid' => $appUuid]);
        } catch (\Throwable $e) {
            return $this->mcpError($request, 'Failed to load repositories: '.$e->getMessage(), ['resource_uuid' => $appUuid]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'github_app_uuid' => $schema->string()->description('GitHub app UUID.')->required(),
        ];
    }
}
