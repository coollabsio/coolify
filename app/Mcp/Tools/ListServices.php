<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListServices extends Tool
{
    protected string $name = 'list_services';

    protected string $description = 'List services (multi-container stacks) owned by the authenticated team. Optional filters: project_uuid, name.';

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

        $query = Service::whereHas('environment.project', fn ($q) => $q->where('team_id', $teamId))
            ->with(['environment.project:id,uuid,name,team_id'])
            ->when(is_string($projectUuid), function ($query) use ($projectUuid, $teamId) {
                $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
                if (! $project) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $query->whereHas('environment', fn ($q) => $q->where('project_id', $project->id));
            })
            ->when(is_string($name), fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($name).'%']));

        $total = (clone $query)->count();

        $summaries = $query
            ->orderBy('name')
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($svc) => [
                'uuid' => $svc->uuid,
                'name' => $svc->name,
                'status' => $svc->status ?? null,
                'project_uuid' => $svc->environment?->project?->uuid,
                'project_name' => $svc->environment?->project?->name,
                'environment_name' => $svc->environment?->name,
            ])
            ->values()
            ->all();

        $extra = array_filter([
            'project_uuid' => $projectUuid,
            'name' => $name,
        ], fn ($v) => $v !== null);

        return $this->mcpSuccess($request, $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_services', $args, $total, $extra),
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
