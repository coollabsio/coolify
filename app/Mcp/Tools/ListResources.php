<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListResources extends Tool
{
    protected string $name = 'list_resources';

    protected string $description = 'List all resources (applications, services, databases) owned by the authenticated team. Optional filters: type, project_uuid, tag.';

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

        $typeFilter = $request->get('type');
        if ($typeFilter !== null && (! is_string($typeFilter) || ! in_array($typeFilter, ['application', 'service', 'database'], true))) {
            return $this->mcpError($request, 'type must be one of: application, service, database.');
        }

        $projectUuid = $request->get('project_uuid');
        if ($projectUuid !== null && (! is_string($projectUuid) || $projectUuid === '')) {
            return $this->mcpError($request, 'project_uuid must be a non-empty string.');
        }

        $tagName = $request->get('tag');
        if ($tagName !== null && (! is_string($tagName) || trim($tagName) === '')) {
            return $this->mcpError($request, 'tag argument must be a non-empty string.');
        }

        $args = $this->paginationArgs($request);

        $projectsQuery = Project::where('team_id', $teamId);
        if (is_string($projectUuid)) {
            $projectsQuery->where('uuid', $projectUuid);
        }
        $projects = $projectsQuery->get();

        $items = collect();

        foreach ($projects as $project) {
            if ($typeFilter === null || $typeFilter === 'application') {
                $appsQuery = $project->applications();
                if (is_string($tagName)) {
                    $appsQuery->whereHas('tags', fn ($q) => $q->where('name', $tagName));
                }
                foreach ($appsQuery->get() as $app) {
                    $items->push([
                        'uuid' => $app->uuid,
                        'name' => $app->name,
                        'type' => 'application',
                        'status' => $app->status,
                        'project_uuid' => $project->uuid,
                        'project_name' => $project->name,
                    ]);
                }
            }

            if ($typeFilter === null || $typeFilter === 'service') {
                $servicesQuery = $project->services();
                if (is_string($tagName)) {
                    $servicesQuery->whereHas('tags', fn ($q) => $q->where('name', $tagName));
                }
                foreach ($servicesQuery->get() as $svc) {
                    $items->push([
                        'uuid' => $svc->uuid,
                        'name' => $svc->name,
                        'type' => 'service',
                        'status' => $svc->status ?? null,
                        'project_uuid' => $project->uuid,
                        'project_name' => $project->name,
                    ]);
                }
            }

            if ($typeFilter === null || $typeFilter === 'database') {
                foreach ($project->databases() as $db) {
                    if (is_string($tagName) && method_exists($db, 'tags') && ! $db->tags->contains('name', $tagName)) {
                        continue;
                    }
                    $items->push([
                        'uuid' => $db->uuid,
                        'name' => $db->name,
                        'type' => method_exists($db, 'type') ? $db->type() : 'database',
                        'status' => $db->status ?? null,
                        'project_uuid' => $project->uuid,
                        'project_name' => $project->name,
                    ]);
                }
            }
        }

        $sorted = $items->sortBy('name')->values();
        $total = $sorted->count();
        $page = $sorted->slice($args['offset'], $args['per_page'])->values()->all();

        $extra = array_filter([
            'type' => $typeFilter,
            'project_uuid' => $projectUuid,
            'tag' => $tagName,
        ], fn ($v) => $v !== null);

        return $this->mcpSuccess($request, $this->respond(
            $page,
            [],
            $this->paginationMeta('list_resources', $args, $total, $extra),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Optional filter: application, service, or database.'),
            'project_uuid' => $schema->string()->description('Optional project UUID filter.'),
            'tag' => $schema->string()->description('Optional tag name filter.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
