<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListDatabases extends Tool
{
    protected string $name = 'list_databases';

    protected string $description = 'List standalone databases owned by the authenticated team. Filters: project_uuid, environment_uuid, server_uuid, status, name.';

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

        $projectsQuery = Project::where('team_id', $teamId);
        if (is_string($projectUuid)) {
            $projectsQuery->where('uuid', $projectUuid);
        }
        $projects = $projectsQuery->get();

        $envIdFilter = null;
        if (is_string($environmentUuid)) {
            $env = Environment::ownedByCurrentTeamAPI($teamId)->where('uuid', $environmentUuid)->first();
            if (! $env) {
                return $this->mcpSuccess($request, $this->respond(
                    [],
                    [],
                    $this->paginationMeta('list_databases', $args, 0, array_filter([
                        'project_uuid' => $projectUuid,
                        'environment_uuid' => $environmentUuid,
                        'server_uuid' => $serverUuid,
                        'status' => $status,
                        'name' => $name,
                    ], fn ($v) => $v !== null)),
                ));
            }
            $envIdFilter = $env->id;
        }

        $destinationIds = null;
        if (is_string($serverUuid)) {
            $server = Server::whereTeamId($teamId)->where('uuid', $serverUuid)->first();
            if (! $server) {
                return $this->mcpSuccess($request, $this->respond(
                    [],
                    [],
                    $this->paginationMeta('list_databases', $args, 0, array_filter([
                        'project_uuid' => $projectUuid,
                        'environment_uuid' => $environmentUuid,
                        'server_uuid' => $serverUuid,
                        'status' => $status,
                        'name' => $name,
                    ], fn ($v) => $v !== null)),
                ));
            }
            $destinationIds = $server->standaloneDockers()->pluck('id')
                ->merge($server->swarmDockers()->pluck('id'))
                ->all();
        }

        $databases = collect();
        foreach ($projects as $project) {
            foreach ($project->databases() as $db) {
                if ($envIdFilter !== null && (int) $db->environment_id !== $envIdFilter) {
                    continue;
                }
                if (is_array($destinationIds) && ! in_array($db->destination_id, $destinationIds, true)) {
                    continue;
                }
                if (is_string($name) && ! str_contains(strtolower((string) $db->name), strtolower($name))) {
                    continue;
                }
                if (is_string($status)) {
                    $dbStatus = strtolower((string) ($db->status ?? ''));
                    if (! str_contains($dbStatus, strtolower($status))) {
                        continue;
                    }
                }
                $databases->push([
                    'uuid' => $db->uuid,
                    'name' => $db->name,
                    'status' => $db->status ?? null,
                    'type' => method_exists($db, 'type') ? $db->type() : class_basename($db),
                    'project_uuid' => $project->uuid,
                    'project_name' => $project->name,
                ]);
            }
        }

        $sorted = $databases->sortBy('name')->values();
        $total = $sorted->count();
        $summaries = $sorted->slice($args['offset'], $args['per_page'])->values()->all();

        $extra = array_filter([
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
            'server_uuid' => $serverUuid,
            'status' => $status,
            'name' => $name,
        ], fn ($v) => $v !== null);

        return $this->mcpSuccess($request, $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_databases', $args, $total, $extra),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('Optional project UUID filter.'),
            'environment_uuid' => $schema->string()->description('Optional environment UUID filter.'),
            'server_uuid' => $schema->string()->description('Optional server UUID filter.'),
            'status' => $schema->string()->description('Optional status substring filter.'),
            'name' => $schema->string()->description('Optional name substring filter.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
