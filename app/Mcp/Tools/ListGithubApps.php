<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\GithubApp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListGithubApps extends Tool
{
    protected string $name = 'list_github_apps';

    protected string $description = 'List GitHub apps available to the authenticated team (team-owned or system-wide). Secrets are never returned.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $args = $this->paginationArgs($request);

        $query = GithubApp::query()
            ->where(function ($q) use ($teamId) {
                $q->where('team_id', $teamId)->orWhere('is_system_wide', true);
            })
            ->orderBy('name');

        $total = (clone $query)->count();

        $apps = $query
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($app) => $this->scrubSensitive([
                'uuid' => $app->uuid,
                'name' => $app->name,
                'organization' => $app->organization,
                'api_url' => $app->api_url,
                'html_url' => $app->html_url,
                'is_system_wide' => $app->is_system_wide,
                'is_public' => $app->is_public,
                'type' => $app->type ?? 'github_app',
            ]))
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond(
            $apps,
            [],
            $this->paginationMeta('list_github_apps', $args, $total),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
