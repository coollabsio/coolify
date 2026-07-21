<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListDatabases extends Tool
{
    protected string $name = 'list_databases';

    protected string $description = 'List standalone databases owned by the authenticated team. Optional filters: project_uuid, name.';

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

        $databases = collect();
        foreach ($projects as $project) {
            foreach ($project->databases() as $db) {
                if (is_string($name) && ! str_contains(strtolower((string) $db->name), strtolower($name))) {
                    continue;
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
            'name' => $schema->string()->description('Optional name substring filter.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
