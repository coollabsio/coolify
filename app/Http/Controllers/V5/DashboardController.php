<?php

namespace App\Http\Controllers\V5;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const SELECTED_PROJECT_SESSION_KEY = 'v5.selectedProjectUuid';

    private const SELECTED_ENVIRONMENT_SESSION_KEY = 'v5.selectedEnvironmentUuid';

    public function __invoke(Request $request, FluxHealth $fluxHealth): Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        return Inertia::render('Dashboard', [
            'flux' => $fluxHealth->check(),
            'projects' => $projects,
            'selectedProjectUuid' => $selectedProject['uuid'] ?? null,
            'selectedEnvironmentUuid' => $selectedEnvironment['uuid'] ?? null,
        ]);
    }

    public function clustersIndex(Request $request, FluxHealth $fluxHealth): Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        return Inertia::render('Clusters', [
            'flux' => $fluxHealth->check(),
            'clusters' => $this->clusters($currentTeam),
            'projects' => $projects,
            'selectedProjectUuid' => $selectedProject['uuid'] ?? null,
            'selectedEnvironmentUuid' => $selectedEnvironment['uuid'] ?? null,
        ]);
    }

    public function updateSelection(Request $request): \Illuminate\Http\Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team) {
            abort(403);
        }

        $validated = $request->validate([
            'project_uuid' => ['required', 'string'],
            'environment_uuid' => ['nullable', 'string'],
        ]);

        $project = $this->projectQuery($currentTeam)
            ->where('uuid', $validated['project_uuid'])
            ->first();

        if (! $project instanceof Project) {
            abort(403);
        }

        $environment = $this->selectedEnvironment($project, $validated['environment_uuid'] ?? null);

        $request->session()->put([
            self::SELECTED_PROJECT_SESSION_KEY => $project->uuid,
            self::SELECTED_ENVIRONMENT_SESSION_KEY => $environment?->uuid,
        ]);

        return response()->noContent();
    }

    public function storeCluster(Request $request): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('v5_clusters', 'name')->where('team_id', $currentTeam->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $cluster = V5Cluster::query()->create([
            'team_id' => $currentTeam->id,
            'created_by_user_id' => $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $cluster->load(['servers' => fn ($query) => $query
            ->with('privateKey')
            ->orderBy('name')]);
        $cluster->loadCount('servers');

        return response()->json([
            'cluster' => $this->serializeCluster($cluster),
        ], 201);
    }

    /**
     * @return array<int, array{id: string, name: string, description: string|null, serversCount: int, servers: array<int, array{id: string, name: string, host: string, sshUser: string, sshPort: int, status: string, capabilities: array<int, string>, builderEnabled: bool, builderCapacity: int, privateKeyName: string|null, lastBootstrappedAt: string|null}>}>
     */
    private function clusters(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        return V5Cluster::query()
            ->where('team_id', $currentTeam->id)
            ->with(['servers' => fn ($query) => $query
                ->with('privateKey')
                ->orderBy('name')])
            ->withCount('servers')
            ->orderBy('name')
            ->get()
            ->map(fn (V5Cluster $cluster) => $this->serializeCluster($cluster))
            ->all();
    }

    /**
     * @return array{id: string, name: string, description: string|null, serversCount: int, servers: array<int, array{id: string, name: string, host: string, sshUser: string, sshPort: int, status: string, capabilities: array<int, string>, builderEnabled: bool, builderCapacity: int, privateKeyName: string|null, lastBootstrappedAt: string|null}>}
     */
    private function serializeCluster(V5Cluster $cluster): array
    {
        return [
            'id' => (string) $cluster->id,
            'name' => $cluster->name,
            'description' => $cluster->description,
            'serversCount' => $cluster->servers_count ?? $cluster->servers->count(),
            'servers' => $cluster->servers->map(fn (V5Server $server) => [
                'id' => (string) $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'sshUser' => $server->ssh_user,
                'sshPort' => $server->ssh_port,
                'status' => $server->status,
                'capabilities' => $server->capabilities ?? [],
                'builderEnabled' => $server->builder_enabled,
                'builderCapacity' => $server->builder_capacity,
                'privateKeyName' => $server->privateKey?->name,
                'lastBootstrappedAt' => $server->last_bootstrapped_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @param  array<int, array{uuid: string, name: string, environments: array<int, array{uuid: string, name: string}>}>  $projects
     * @return array{0: array{uuid: string, name: string, environments: array<int, array{uuid: string, name: string}>}|null, 1: array{uuid: string, name: string}|null}
     */
    private function selectedProjectAndEnvironment(Request $request, array $projects): array
    {
        $selectedProjectUuid = $request->session()->get(self::SELECTED_PROJECT_SESSION_KEY);
        $selectedEnvironmentUuid = $request->session()->get(self::SELECTED_ENVIRONMENT_SESSION_KEY);
        $selectedProject = null;

        foreach ($projects as $project) {
            if ($project['uuid'] === $selectedProjectUuid) {
                $selectedProject = $project;

                break;
            }
        }

        $selectedProject ??= $projects[0] ?? null;
        $selectedEnvironment = null;

        foreach ($selectedProject['environments'] ?? [] as $environment) {
            if ($environment['uuid'] === $selectedEnvironmentUuid) {
                $selectedEnvironment = $environment;

                break;
            }
        }

        $selectedEnvironment ??= $selectedProject['environments'][0] ?? null;

        return [$selectedProject, $selectedEnvironment];
    }

    private function selectedEnvironment(Project $project, ?string $environmentUuid): ?Environment
    {
        if ($environmentUuid === null) {
            return $project->environments->first();
        }

        $environment = $project->environments->firstWhere('uuid', $environmentUuid);

        if (! $environment instanceof Environment) {
            abort(422, 'The selected environment is not available for the selected project.');
        }

        return $environment;
    }

    /**
     * @return array<int, array{uuid: string, name: string, environments: array<int, array{uuid: string, name: string}>}>
     */
    private function projects(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        return $this->projectQuery($currentTeam)
            ->get()
            ->map(fn (Project $project) => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'environments' => $project->environments
                    ->map(fn ($environment) => [
                        'uuid' => $environment->uuid,
                        'name' => $environment->name,
                    ])
                    ->all(),
            ])
            ->all();
    }

    private function projectQuery(Team $currentTeam): Builder
    {
        return Project::query()
            ->select(['id', 'uuid', 'name', 'team_id'])
            ->where('team_id', $currentTeam->id)
            ->with(['environments' => fn ($query) => $query
                ->select(['id', 'uuid', 'name', 'project_id'])
                ->orderByRaw("CASE WHEN LOWER(name) = 'production' THEN 0 ELSE 1 END")
                ->orderByRaw('LOWER(name)')])
            ->orderByRaw('LOWER(name)');
    }
}
