<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListApplications extends Tool
{
    protected string $name = 'list_applications';

    protected string $description = 'List applications owned by the authenticated team. Filters: tag, project_uuid, environment_uuid, server_uuid, status, name.';

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

        $tagName = $request->get('tag');
        if ($tagName !== null && (! is_string($tagName) || trim($tagName) === '')) {
            return $this->mcpError($request, 'tag argument must be a non-empty string.');
        }

        $projectUuid = $request->get('project_uuid');
        if ($projectUuid !== null && (! is_string($projectUuid) || $projectUuid === '')) {
            return $this->mcpError($request, 'project_uuid must be a non-empty string.');
        }

        $environmentUuid = $request->get('environment_uuid');
        if ($environmentUuid !== null && (! is_string($environmentUuid) || $environmentUuid === '')) {
            return $this->mcpError($request, 'environment_uuid must be a non-empty string.');
        }

        $serverUuid = $request->get('server_uuid');
        if ($serverUuid !== null && (! is_string($serverUuid) || $serverUuid === '')) {
            return $this->mcpError($request, 'server_uuid must be a non-empty string.');
        }

        $status = $request->get('status');
        if ($status !== null && (! is_string($status) || trim($status) === '')) {
            return $this->mcpError($request, 'status must be a non-empty string.');
        }

        $name = $request->get('name');
        if ($name !== null && (! is_string($name) || trim($name) === '')) {
            return $this->mcpError($request, 'name argument must be a non-empty string.');
        }

        $args = $this->paginationArgs($request);

        $query = Application::ownedByCurrentTeamAPI($teamId)
            ->with(['environment.project:id,uuid,name,team_id'])
            ->applyListFilters($teamId, [
                'tag' => $tagName,
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'server_uuid' => $serverUuid,
                'status' => $status,
                'name' => $name,
            ]);

        $total = (clone $query)->count();

        $summaries = $query
            ->orderBy('name')
            ->orderBy('id')
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($app) => [
                'uuid' => $app->uuid,
                'name' => $app->name,
                'status' => $app->status,
                'fqdn' => $app->fqdn,
                'git_repository' => $app->git_repository,
                'project_uuid' => $app->environment?->project?->uuid,
                'project_name' => $app->environment?->project?->name,
                'environment_name' => $app->environment?->name,
                'environment_uuid' => $app->environment?->uuid,
            ])
            ->values()
            ->all();

        $extra = array_filter([
            'tag' => $tagName,
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
            'server_uuid' => $serverUuid,
            'status' => $status,
            'name' => $name,
        ], fn ($v) => $v !== null);

        return $this->mcpSuccess($request, $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_applications', $args, $total, $extra),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tag' => $schema->string()->description('Optional tag name filter.'),
            'project_uuid' => $schema->string()->description('Optional project UUID filter.'),
            'environment_uuid' => $schema->string()->description('Optional environment UUID filter.'),
            'server_uuid' => $schema->string()->description('Optional server UUID filter (via destination).'),
            'status' => $schema->string()->description('Optional status substring filter (e.g. running, exited).'),
            'name' => $schema->string()->description('Optional name substring filter.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
