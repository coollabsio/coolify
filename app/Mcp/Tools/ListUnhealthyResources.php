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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListUnhealthyResources extends Tool
{
    protected string $name = 'list_unhealthy_resources';

    protected string $description = 'List team resources that look unhealthy or down. Prefer sample_only=true first (cheap sample + counts). Apps/DBs use SQL filters; services need a lightweight status scan. Full mode paginates without hydrating the full unhealthy set. Default per_page 20.';

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
            ->orderBy('id')
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

        // --- Services / DBs: count always; hydrate samples only when sample_only ---
        [$serviceCount, $serviceSamples] = $this->collectUnhealthyServices(
            $teamId,
            $sampleOnly,
            $samplePerType,
            skip: 0,
            take: $sampleOnly ? $samplePerType : 0,
        );

        [$dbCount, $dbSamples] = $this->collectUnhealthyDatabases(
            $teamId,
            $sampleOnly,
            $samplePerType,
            skip: 0,
            take: $sampleOnly ? $samplePerType : 0,
        );

        $summary = [
            'total' => $unhealthyServers->count() + $appCount + $serviceCount + $dbCount,
            'servers' => $unhealthyServers->count(),
            'applications' => $appCount,
            'services' => $serviceCount,
            'databases' => $dbCount,
        ];

        if ($sampleOnly) {
            $unhealthyApps = (clone $appQuery)
                ->orderBy('name')
                ->orderBy('id')
                ->limit($samplePerType)
                ->get()
                ->map(fn ($app) => $this->mapApplication($app));

            return $this->mcpSuccess($request, $this->respond([
                'sample_only' => true,
                'sample_per_type' => $samplePerType,
                'summary' => $summary,
                'samples' => [
                    'servers' => $unhealthyServers->take($samplePerType)->values()->all(),
                    'applications' => $unhealthyApps->values()->all(),
                    'services' => $serviceSamples->values()->all(),
                    'databases' => $dbSamples->values()->all(),
                ],
                'next' => [
                    'tool' => 'list_unhealthy_resources',
                    'args' => ['sample_only' => false, 'page' => 1, 'per_page' => 20],
                    'hint' => 'Full paginated unhealthy list',
                ],
            ]));
        }

        // Full mode: paginate by type group without loading the full unhealthy set.
        // Global order matches sortBy(['type','name']): application, server, service, then standalone-*.
        $page = $this->paginateFullList(
            $teamId,
            $appQuery,
            $unhealthyServers,
            $appCount,
            $serviceCount,
            $dbCount,
            $args['offset'],
            $args['per_page'],
        );

        return $this->mcpSuccess($request, $this->respond(
            [
                'unhealthy' => $page,
                'summary' => $summary,
            ],
            [],
            $this->paginationMeta('list_unhealthy_resources', $args, $summary['total'], ['sample_only' => false]),
        ));
    }

    /**
     * Walk type groups in sorted order and fetch only the current page window.
     *
     * @param  Collection<int, array<string, mixed>>  $unhealthyServers
     * @return list<array<string, mixed>>
     */
    private function paginateFullList(
        int $teamId,
        Builder $appQuery,
        Collection $unhealthyServers,
        int $appCount,
        int $serviceCount,
        int $dbCount,
        int $offset,
        int $perPage,
    ): array {
        $skip = $offset;
        $need = $perPage;
        $page = [];

        // 1) applications (type = application)
        if ($need > 0) {
            if ($skip >= $appCount) {
                $skip -= $appCount;
            } else {
                $take = min($need, $appCount - $skip);
                $rows = (clone $appQuery)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->skip($skip)
                    ->take($take)
                    ->get()
                    ->map(fn ($app) => $this->mapApplication($app))
                    ->all();
                $page = array_merge($page, $rows);
                $need -= count($rows);
                $skip = 0;
            }
        }

        // 2) servers (type = server) — small set
        $serverCount = $unhealthyServers->count();
        if ($need > 0) {
            if ($skip >= $serverCount) {
                $skip -= $serverCount;
            } else {
                $rows = $unhealthyServers->slice($skip, $need)->values()->all();
                $page = array_merge($page, $rows);
                $need -= count($rows);
                $skip = 0;
            }
        }

        // 3) services (type = service)
        if ($need > 0) {
            if ($skip >= $serviceCount) {
                $skip -= $serviceCount;
            } else {
                // Count already known from the earlier full scan; stop once the page window is full.
                [, $serviceRows] = $this->collectUnhealthyServices(
                    $teamId,
                    sampleOnly: false,
                    samplePerType: 1,
                    skip: $skip,
                    take: $need,
                    needsCount: false,
                );
                $rows = $serviceRows->values()->all();
                $page = array_merge($page, $rows);
                $need -= count($rows);
                $skip = 0;
            }
        }

        // 4) databases by type() alphabetically (standalone-*)
        if ($need > 0) {
            if ($skip >= $dbCount) {
                // nothing left
            } else {
                [, $dbRows] = $this->collectUnhealthyDatabases(
                    $teamId,
                    sampleOnly: false,
                    samplePerType: 1,
                    skip: $skip,
                    take: $need,
                );
                $page = array_merge($page, $dbRows->values()->all());
            }
        }

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapApplication(Application $app): array
    {
        return [
            'type' => 'application',
            'uuid' => $app->uuid,
            'name' => $app->name,
            'status' => $app->status,
            'project_uuid' => $app->environment?->project?->uuid,
            'project_name' => $app->environment?->project?->name,
            'reason' => 'status_not_running',
        ];
    }

    /**
     * Scan services for unhealthy status. When $needsCount is false, stop once the page window is full
     * (callers that already know the total can skip the rest of the table).
     *
     * @return array{0: int, 1: Collection<int, array<string, mixed>>}
     */
    private function collectUnhealthyServices(
        int $teamId,
        bool $sampleOnly,
        int $samplePerType,
        int $skip = 0,
        ?int $take = null,
        bool $needsCount = true,
    ): array {
        $base = Service::whereHas('environment.project', fn ($q) => $q->where('team_id', $teamId))
            ->with([
                'environment.project:id,uuid,name,team_id',
                'applications:id,service_id,status,exclude_from_status',
                'databases:id,service_id,status,exclude_from_status',
            ])
            ->orderBy('name')
            ->orderBy('id');

        $unhealthy = collect();
        $serviceCount = 0;
        $skipped = 0;
        // take=0 means count-only (no row hydration beyond status check).
        $limit = $take === null ? ($sampleOnly ? $samplePerType : PHP_INT_MAX) : max(0, $take);

        // Chunk so large teams do not hydrate every service at once.
        $base->chunk(100, function ($chunk) use ($skip, $limit, $needsCount, &$unhealthy, &$serviceCount, &$skipped) {
            foreach ($chunk as $svc) {
                if ($this->looksHealthy($svc->status ?? null)) {
                    continue;
                }
                $serviceCount++;

                if ($limit === 0 || $unhealthy->count() >= $limit) {
                    if (! $needsCount) {
                        // Page window full and total already known — stop scanning.
                        return false;
                    }

                    // Still count remaining unhealthy services.
                    continue;
                }

                if ($skipped < $skip) {
                    $skipped++;

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
    private function collectUnhealthyDatabases(
        int $teamId,
        bool $sampleOnly,
        int $samplePerType,
        int $skip = 0,
        ?int $take = null,
    ): array {
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

        // Walk model types in alphabetical type() order to match global sortBy(['type','name']).
        $models = collect(STANDALONE_DATABASE_MODELS)
            ->mapWithKeys(function ($modelClass, $typeKey) {
                /** @var class-string $modelClass */
                $model = new $modelClass;
                $type = method_exists($model, 'type') ? $model->type() : 'standalone-'.$typeKey;

                return [$type => $modelClass];
            })
            ->sortKeys();

        $skipped = 0;
        // take=0 means count-only (no row hydration).
        $limit = $take === null ? ($sampleOnly ? $samplePerType : PHP_INT_MAX) : max(0, $take);

        foreach ($models as $type => $modelClass) {
            $dq = $modelClass::query()->whereIn('environment_id', $envIds);
            $this->scopeNotHealthyRunning($dq);
            $count = (clone $dq)->count();
            $dbCount += $count;

            if ($limit === 0 || $dbItems->count() >= $limit) {
                continue;
            }

            if ($sampleOnly) {
                $remaining = $limit - $dbItems->count();
                $rows = $dq->orderBy('name')->orderBy('id')->limit($remaining)->get(['uuid', 'name', 'status', 'environment_id']);
            } else {
                if ($skipped + $count <= $skip) {
                    $skipped += $count;

                    continue;
                }
                $localSkip = max(0, $skip - $skipped);
                $localTake = min($count - $localSkip, $limit - $dbItems->count());
                if ($localTake <= 0) {
                    $skipped += $count;

                    continue;
                }
                $rows = $dq->orderBy('name')->orderBy('id')->skip($localSkip)->take($localTake)->get(['uuid', 'name', 'status', 'environment_id']);
                $skipped += $count;
            }

            foreach ($rows as $db) {
                $projectId = $envToProject[$db->environment_id] ?? null;
                $project = $projectId ? $projects->get($projectId) : null;
                $dbItems->push([
                    'type' => $type,
                    'resource_kind' => 'database',
                    'uuid' => $db->uuid,
                    'name' => $db->name,
                    'status' => $db->status ?? null,
                    'project_uuid' => $project?->uuid,
                    'project_name' => $project?->name,
                    'reason' => 'status_not_running',
                ]);
            }

            if ($sampleOnly && $dbItems->count() >= $limit) {
                break;
            }
        }

        if ($sampleOnly) {
            $dbItems = $dbItems->unique('uuid')->take($samplePerType)->values();
        }

        return [$dbCount, $dbItems->values()];
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
