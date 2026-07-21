<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\McpStatusFilters;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

        $projects = Project::where('team_id', $teamId)->select('id', 'uuid', 'name')->get();

        $appCount = 0;
        $serviceCount = 0;
        $databaseCount = 0;
        $projectSummaries = [];

        foreach ($projects as $project) {
            $apps = $project->applications()->count();
            $services = $project->services()->count();
            $databases = $project->databases()->count();

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

        $serverIds = $servers->pluck('id');
        $openDeployments = $serverIds->isEmpty()
            ? 0
            : ApplicationDeploymentQueue::query()
                ->whereIn('server_id', $serverIds)
                ->whereIn('status', ['in_progress', 'queued'])
                ->count();

        // Count-only health hints (no full model hydration for apps).
        $appNotRunningQuery = Application::ownedByCurrentTeamAPI($teamId);
        $this->scopeNotHealthyRunning($appNotRunningQuery);
        $nonRunningApps = $appNotRunningQuery->count();

        // Services: status is aggregated; count via lightweight filter (avoid loading all relations when possible).
        $nonRunningServices = Service::whereHas('environment.project', fn ($q) => $q->where('team_id', $teamId))
            ->with(['applications:id,service_id,status,exclude_from_status', 'databases:id,service_id,status,exclude_from_status'])
            ->get()
            ->filter(fn ($svc) => ! $this->looksHealthy($svc->status ?? null))
            ->count();

        $nonRunningDatabases = 0;
        foreach ($projects as $project) {
            $envIds = $project->environments()->pluck('id');
            if ($envIds->isEmpty()) {
                continue;
            }
            foreach (STANDALONE_DATABASE_MODELS as $modelClass) {
                $dq = $modelClass::query()->whereIn('environment_id', $envIds);
                $this->scopeNotHealthyRunning($dq);
                $nonRunningDatabases += $dq->count();
            }
        }

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

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
