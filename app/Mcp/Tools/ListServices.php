<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListServices extends Tool
{
    protected string $name = 'list_services';

    protected string $description = 'List services (multi-container stacks) owned by the authenticated team. Filters: project_uuid, environment_uuid, server_uuid, status, name.';

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
            ->when(is_string($environmentUuid), function ($query) use ($environmentUuid, $teamId) {
                $env = Environment::ownedByCurrentTeamAPI($teamId)->where('uuid', $environmentUuid)->first();
                if (! $env) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $query->where('environment_id', $env->id);
            })
            ->when(is_string($serverUuid), function ($query) use ($serverUuid, $teamId) {
                $server = Server::whereTeamId($teamId)->where('uuid', $serverUuid)->first();
                if (! $server) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $standaloneDockerIds = $server->standaloneDockers()->pluck('id');
                $swarmDockerIds = $server->swarmDockers()->pluck('id');
                $query->where(function ($q) use ($server, $standaloneDockerIds, $swarmDockerIds) {
                    $q->where('server_id', $server->id)
                        ->orWhere(function ($inner) use ($standaloneDockerIds) {
                            $inner->where('destination_type', StandaloneDocker::class)
                                ->whereIn('destination_id', $standaloneDockerIds);
                        })
                        ->orWhere(function ($inner) use ($swarmDockerIds) {
                            $inner->where('destination_type', SwarmDocker::class)
                                ->whereIn('destination_id', $swarmDockerIds);
                        });
                });
            })
            ->when(is_string($name), fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($name).'%']));

        if (is_string($status)) {
            // Status is a computed accessor over applications/databases, so filter
            // in PHP — but scan in chunks so large teams never hydrate every service.
            [$total, $services] = $this->paginateServicesByStatus(
                $query,
                trim($status),
                $args['offset'],
                $args['per_page'],
            );
        } else {
            $total = (clone $query)->count();
            $services = $query
                ->orderBy('name')
                ->orderBy('id')
                ->skip($args['offset'])
                ->take($args['per_page'])
                ->get();
        }

        $summaries = $services
            ->map(fn ($svc) => [
                'uuid' => $svc->uuid,
                'name' => $svc->name,
                'status' => $svc->status ?? null,
                'project_uuid' => $svc->environment?->project?->uuid,
                'project_name' => $svc->environment?->project?->name,
                'environment_name' => $svc->environment?->name,
                'environment_uuid' => $svc->environment?->uuid,
            ])
            ->values()
            ->all();

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
            $this->paginationMeta('list_services', $args, $total, $extra),
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

    /**
     * Filter services by computed status in chunks, keeping only the requested page in memory.
     *
     * @param  Builder<Service>  $query
     * @return array{0: int, 1: Collection<int, Service>}
     */
    private function paginateServicesByStatus(Builder $query, string $status, int $offset, int $perPage): array
    {
        $statusNeedle = strtolower($status);
        $matched = collect();
        $total = 0;
        $pageEnd = $offset + $perPage;

        $query
            ->with([
                'applications:id,service_id,status,exclude_from_status',
                'databases:id,service_id,status,exclude_from_status',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->chunk(100, function ($chunk) use ($statusNeedle, $offset, $pageEnd, &$matched, &$total) {
                foreach ($chunk as $service) {
                    if (! str_contains(strtolower((string) $service->status), $statusNeedle)) {
                        continue;
                    }

                    if ($total >= $offset && $total < $pageEnd) {
                        $matched->push($service);
                    }

                    $total++;
                }
            });

        return [$total, $matched->values()];
    }
}
