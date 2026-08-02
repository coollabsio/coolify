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

class ListGithubBranches extends Tool
{
    protected string $name = 'list_github_branches';

    protected string $description = 'List branches for a repository via a team GitHub app (calls GitHub API). Soft-registered only when a GitHub app exists.';

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
        $owner = $request->get('owner');
        $repo = $request->get('repo');

        if (! is_string($appUuid) || $appUuid === '') {
            return $this->mcpError($request, 'github_app_uuid argument is required.');
        }
        if (! is_string($owner) || $owner === '') {
            return $this->mcpError($request, 'owner argument is required.');
        }
        if (! is_string($repo) || $repo === '') {
            return $this->mcpError($request, 'repo argument is required.');
        }
        // GitHub path segments only — reject /, .., spaces, etc. before interpolating into the API path.
        if (! $this->isValidGithubPathSegment($owner)) {
            return $this->mcpError($request, 'owner must be a valid GitHub login or organization name.');
        }
        if (! $this->isValidGithubPathSegment($repo)) {
            return $this->mcpError($request, 'repo must be a valid GitHub repository name.');
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

        try {
            // Anonymous access only for public sources. Missing credentials on a private
            // app are a configuration error — do not fall back to anonymous GitHub API
            // (that can silently return branches from a same-named public repo).
            $hasInstallationCredentials = filled($githubApp->app_id)
                && filled($githubApp->installation_id)
                && filled($githubApp->private_key_id);

            if (! $githubApp->is_public && ! $hasInstallationCredentials) {
                return $this->mcpError(
                    $request,
                    'This GitHub app is not public and is missing installation credentials. Configure the app, or use a public GitHub source.',
                    ['resource_uuid' => $appUuid],
                );
            }

            $token = $githubApp->is_public ? null : generateGithubInstallationToken($githubApp);
            $branches = collect();
            $page = 1;
            $maxPages = 20;

            while ($page <= $maxPages) {
                $response = Http::GitHub($githubApp->api_url, $token)
                    ->timeout(20)
                    ->get("/repos/{$owner}/{$repo}/branches", ['per_page' => 100, 'page' => $page]);

                if ($response->failed()) {
                    $status = $response->status();
                    $hint = match (true) {
                        $status === 401, $status === 403 => 'GitHub app credentials/installation invalid or missing permissions.',
                        $status === 404 => 'Repository not found or not accessible to this GitHub app.',
                        default => 'GitHub API request failed.',
                    };

                    return $this->mcpError($request, "{$hint} (HTTP {$status})", ['resource_uuid' => $appUuid]);
                }

                $batch = collect($response->json() ?? []);
                if ($batch->isEmpty()) {
                    break;
                }

                $branches = $branches->merge($batch);
                if ($batch->count() < 100) {
                    break;
                }
                $page++;
            }

            $summaries = $branches->map(fn ($branch) => [
                'name' => data_get($branch, 'name'),
                'protected' => data_get($branch, 'protected'),
                'commit_sha' => data_get($branch, 'commit.sha'),
            ])->values()->all();

            return $this->mcpSuccess($request, $this->respond([
                'github_app_uuid' => $appUuid,
                'owner' => $owner,
                'repo' => $repo,
                'branches' => $summaries,
            ]), ['resource_uuid' => $appUuid]);
        } catch (\Throwable $e) {
            return $this->mcpError($request, 'Failed to load branches: '.$e->getMessage(), ['resource_uuid' => $appUuid]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'github_app_uuid' => $schema->string()->description('GitHub app UUID.')->required(),
            'owner' => $schema->string()->description('Repository owner.')->required(),
            'repo' => $schema->string()->description('Repository name.')->required(),
        ];
    }

    /**
     * GitHub owner/repo path segments: letters, digits, underscore, period, hyphen only.
     */
    private function isValidGithubPathSegment(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+$/', $value);
    }
}
