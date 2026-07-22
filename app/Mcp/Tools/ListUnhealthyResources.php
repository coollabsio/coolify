<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\McpStatusFilters;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListUnhealthyResources extends Tool
{
    protected string $name = 'list_unhealthy_resources';

    protected string $description = 'List team resources that look unhealthy or down. Prefer sample_only=true first (cheap sample + counts). Apps/DBs use SQL filters; services need a lightweight status scan. Default per_page 20.';

    use BuildsResponse;
    use McpStatusFilters;
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

        $sampleOnly = filter_var($request->get('sample_only'), FILTER_VALIDATE_BOOLEAN);
        $samplePerType = max(1, min(20, (int) ($request->get('sample_per_type') ?? 5)));

        // Prefer smaller pages for this heavy tool (default 20 unless caller sets per_page).
        if ($request->get('per_page') === null) {
            $request->merge(['per_page' => 20]);
        }
        $args = $this->paginationArgs($request);

        // --- Servers (small set; filter via settings) ---
        $unhealthyServers = Server::whereTeamId($teamId)
            ->with('settings:id,server_id,is_reachable,is_usable')
            ->whereHas('settings', function ($q) {
                $q->where('is_reachable', false)->orWhere('is_usable', false);
            })
            ->orderBy('name')
            ->get()
            ->map(function ($server) {
                $reachable = (bool) $server->settings?->is_reachable;
                $usable = (bool) $server->settings?->is_usable;

                return [
                    'type' => 'server',
                    'uuid' => $server->uuid,
                    'name' => $server->name,
                    'status' => $reachable ? 'unreachable_or_unusable' : 'unreachable',
                    'is_reachable' => $reachable,
                    'is_usable' => $usable,
                    'reason' => ! $reachable ? 'server_unreachable' : 'server_not_usable',
                ];
            });

        // --- Applications (SQL on status column) ---
        $appQuery = Application::ownedByCurrentTeamAPI($teamId)
            ->with(['environment.project:id,uuid,name,team_id']);
        $this->scopeNotHealthyRunning($appQuery);
        $appCount = (clone $appQuery)->count();
        $unhealthyApps = $appQuery
            ->orderBy('name')
            ->when($sampleOnly, fn ($q) => $q->limit($samplePerType))
            ->get()
            ->map(fn ($app) => [
                'type' => 'application',
                'uuid' => $app->uuid,
                'name' => $app->name,
                'status' => $app->status,
                'project_uuid' => $app->environment?->project?->uuid,
                'project_name' => $app->environment?->project?->name,
                'reason' => 'status_not_running',
            ]);

        // --- Services: aggregated status accessor; chunk + early exit for samples ---
        [$serviceCount, $unhealthyServices] = $this->collectUnhealthyServices($teamId, $sampleOnly, $samplePerType);

        // --- Databases: SQL per standalone model, team env ids once ---
        [$dbCount, $dbItems] = $this->collectUnhealthyDatabases($teamId, $sampleOnly, $samplePerType);

        $summary = [
            'total' => $unhealthyServers->count() + $appCount + $serviceCount + $dbCount,
            'servers' => $unhealthyServers->count(),
            'applications' => $appCount,
            'services' => $serviceCount,
            'databases' => $dbCount,
        ];

        if ($sampleOnly) {
            return $this->mcpSuccess($request, $this->respond([
                'sample_only' => true,
                'sample_per_type' => $samplePerType,
                'summary' => $summary,
                'samples' => [
                    'servers' => $unhealthyServers->take($samplePerType)->values()->all(),
                    'applications' => $unhealthyApps->values()->all(),
                    'services' => $unhealthyServices->values()->all(),
                    'databases' => $dbItems->values()->all(),
                ],
                'next' => [
                    'tool' => 'list_unhealthy_resources',
                    'args' => ['sample_only' => false, 'page' => 1, 'per_page' => 20],
                    'hint' => 'Full paginated unhealthy list',
                ],
            ]));
        }

        $items = $unhealthyServers
            ->concat($unhealthyApps)
            ->concat($unhealthyServices)
            ->concat($dbItems)
            ->sortBy(['type', 'name'])
            ->values();

        $total = $items->count();
        $page = $items->slice($args['offset'], $args['per_page'])->values()->all();

        return $this->mcpSuccess($request, $this->respond(
            [
                'unhealthy' => $page,
                'summary' => $summary,
            ],
            [],
            $this->paginationMeta('list_unhealthy_resources', $args, $total, ['sample_only' => false]),
        ));
    }

    /**
     * @return array{0: int, 1: Collection<int, array<string, mixed>>}
     */
    private function collectUnhealthyServices(int $teamId, bool $sampleOnly, int $samplePerType): array
    {
        $base = Service::whereHas('environment.project', fn ($q) => $q->where('team_id', $teamId))
            ->with([
                'environment.project:id,uuid,name,team_id',
                'applications:id,service_id,status,exclude_from_status',
                'databases:id,service_id,status,exclude_from_status',
            ])
            ->orderBy('name');

        $unhealthy = collect();
        $serviceCount = 0;

        // Chunk so large teams do not hydrate every service at once.
        $base->chunk(100, function ($chunk) use ($sampleOnly, $samplePerType, &$unhealthy, &$serviceCount) {
            foreach ($chunk as $svc) {
                if ($this->looksHealthy($svc->status ?? null)) {
                    continue;
                }
                $serviceCount++;
                if ($sampleOnly && $unhealthy->count() >= $samplePerType) {
                    continue;
                }
                $unhealthy->push([
                    'type' => 'service',
                    'uuid' => $svc->uuid,
                    'name' => $svc->name,
                    'status' => $svc->status ?? null,
                    'project_uuid' => $svc->environment?->project?->uuid,
                    'project_name' => $svc->environment?->project?->name,
                    'reason' => 'status_not_running',
                ]);
            }
        });

        return [$serviceCount, $unhealthy->values()];
    }

    /**
     * @return array{0: int, 1: Collection<int, array<string, mixed>>}
     */
    private function collectUnhealthyDatabases(int $teamId, bool $sampleOnly, int $samplePerType): array
    {
        $projects = Project::where('team_id', $teamId)->select('id', 'uuid', 'name')->get()->keyBy('id');
        $envToProject = Environment::query()
            ->whereIn('project_id', $projects->keys())
            ->pluck('project_id', 'id');

        $envIds = $envToProject->keys();
        $dbItems = collect();
        $dbCount = 0;

        if ($envIds->isEmpty()) {
            return [0, $dbItems];
        }

        foreach (STANDALONE_DATABASE_MODELS as $modelClass) {
            $dq = $modelClass::query()->whereIn('environment_id', $envIds);
            $this->scopeNotHealthyRunning($dq);
            $dbCount += (clone $dq)->count();

            $rows = $dq->orderBy('name')
                ->when($sampleOnly, fn ($q) => $q->limit($samplePerType))
                ->get(['uuid', 'name', 'status', 'environment_id']);

            foreach ($rows as $db) {
                $projectId = $envToProject[$db->environment_id] ?? null;
                $project = $projectId ? $projects->get($projectId) : null;
                $dbItems->push([
                    'type' => method_exists($db, 'type') ? $db->type() : class_basename($db),
                    'resource_kind' => 'database',
                    'uuid' => $db->uuid,
                    'name' => $db->name,
                    'status' => $db->status ?? null,
                    'project_uuid' => $project?->uuid,
                    'project_name' => $project?->name,
                    'reason' => 'status_not_running',
                ]);
            }
        }

        if ($sampleOnly) {
            $dbItems = $dbItems->unique('uuid')->take($samplePerType)->values();
        }

        return [$dbCount, $dbItems];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sample_only' => $schema->boolean()->description('If true, return only a small sample per type plus full summary counts (cheaper). Prefer this first.'),
            'sample_per_type' => $schema->integer()->description('Sample size per type when sample_only=true (default 5, max 20).'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 20, max 100).'),
        ];
    }
}
