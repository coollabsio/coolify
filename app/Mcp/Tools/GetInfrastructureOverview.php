<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\McpStatusFilters;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetInfrastructureOverview extends Tool
{
    protected string $name = 'get_infrastructure_overview';

    protected string $description = 'High-level overview of the authenticated team: Coolify version, servers, projects with resource counts, open deployments, and SQL-based health_hints counts. Start here or with coolify_help / search_resources.';

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

        $servers = Server::whereTeamId($teamId)
            ->select('id', 'name', 'uuid', 'ip', 'description')
            ->with('settings:id,server_id,is_reachable,is_usable')
            ->get();

        $serverSummaries = $servers
            ->map(fn ($s) => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'ip' => $s->ip,
                'is_reachable' => $s->settings?->is_reachable,
                'is_usable' => $s->settings?->is_usable,
            ])
            ->values()
            ->all();

        $unreachableServers = Server::whereTeamId($teamId)
            ->whereHas('settings', fn ($q) => $q->where('is_reachable', false))
            ->count();

        // One query with relation counts (avoids per-project applications/services/databases fan-out).
        $projects = Project::where('team_id', $teamId)
            ->select('id', 'uuid', 'name')
            ->withCount([
                'applications',
                'services',
                'postgresqls',
                'redis',
                'mongodbs',
                'mysqls',
                'mariadbs',
                'keydbs',
                'dragonflies',
                'clickhouses',
            ])
            ->get();

        $appCount = 0;
        $serviceCount = 0;
        $databaseCount = 0;
        $projectSummaries = [];

        foreach ($projects as $project) {
            $apps = (int) $project->applications_count;
            $services = (int) $project->services_count;
            $databases = (int) (
                $project->postgresqls_count
                + $project->redis_count
                + $project->mongodbs_count
                + $project->mysqls_count
                + $project->mariadbs_count
                + $project->keydbs_count
                + $project->dragonflies_count
                + $project->clickhouses_count
            );

            $appCount += $apps;
            $serviceCount += $services;
            $databaseCount += $databases;

            $projectSummaries[] = [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'counts' => [
                    'applications' => $apps,
                    'services' => $services,
                    'databases' => $databases,
                ],
            ];
        }

        // application_deployment_queues.application_id is varchar; whereHas joins to
        // applications.id (bigint) and breaks on PostgreSQL. Scope via string IDs instead.
        $teamApplicationIds = Application::ownedByCurrentTeamAPI($teamId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        $openDeployments = ApplicationDeploymentQueue::query()
            ->whereIn('application_id', $teamApplicationIds)
            ->whereIn('status', ['in_progress', 'queued'])
            ->count();

        // Count-only health hints (SQL for apps/DBs; chunked scan for service aggregated status).
        $appNotRunningQuery = Application::ownedByCurrentTeamAPI($teamId);
        $this->scopeNotHealthyRunning($appNotRunningQuery);
        $nonRunningApps = $appNotRunningQuery->count();

        $nonRunningServices = $this->countUnhealthyServices($teamId);
        $nonRunningDatabases = $this->countUnhealthyDatabases($projects->pluck('id'));

        return $this->mcpSuccess($request, $this->respond([
            'coolify_version' => config('constants.coolify.version'),
            'servers' => $serverSummaries,
            'projects' => $projectSummaries,
            'counts' => [
                'servers' => count($serverSummaries),
                'projects' => count($projectSummaries),
                'applications' => $appCount,
                'services' => $serviceCount,
                'databases' => $databaseCount,
                'open_deployments' => $openDeployments,
            ],
            'health_hints' => [
                'unreachable_servers' => $unreachableServers,
                'applications_not_running' => $nonRunningApps,
                'services_not_running' => $nonRunningServices,
                'databases_not_running' => $nonRunningDatabases,
                'next' => [
                    'tool' => 'list_unhealthy_resources',
                    'args' => ['sample_only' => true],
                    'hint' => 'Sample unhealthy resources + full counts',
                ],
            ],
        ]));
    }

    /**
     * Service status is a computed accessor over child apps/DBs — scan in chunks
     * so large teams never hydrate every service at once for a count.
     */
    private function countUnhealthyServices(int $teamId): int
    {
        $count = 0;

        Service::whereHas('environment.project', fn ($q) => $q->where('team_id', $teamId))
            ->with([
                'applications:id,service_id,status,exclude_from_status',
                'databases:id,service_id,status,exclude_from_status',
            ])
            ->select(['id', 'uuid'])
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$count) {
                foreach ($chunk as $service) {
                    if (! $this->looksHealthy($service->status ?? null)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * One env-id fetch + one scoped count per standalone DB model (constant query count).
     *
     * @param  Collection<int, int|string>  $projectIds
     */
    private function countUnhealthyDatabases(Collection $projectIds): int
    {
        if ($projectIds->isEmpty()) {
            return 0;
        }

        $environmentIds = Environment::query()
            ->whereIn('project_id', $projectIds)
            ->pluck('id');

        if ($environmentIds->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach (STANDALONE_DATABASE_MODELS as $modelClass) {
            $dbQuery = $modelClass::query()->whereIn('environment_id', $environmentIds);
            $this->scopeNotHealthyRunning($dbQuery);
            $count += $dbQuery->count();
        }

        return $count;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
