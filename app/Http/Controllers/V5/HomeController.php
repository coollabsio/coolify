<?php

namespace App\Http\Controllers\V5;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\Server as V5Server;
use App\Services\Coold\CoolifyCliBootstrap;
use App\Services\Coold\CoolifyCliVersion;
use App\Services\Flux\FluxHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    private const SELECTED_PROJECT_SESSION_KEY = 'v5.selectedProjectUuid';

    private const SELECTED_ENVIRONMENT_SESSION_KEY = 'v5.selectedEnvironmentUuid';

    public function __invoke(Request $request, FluxHealth $fluxHealth): Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        return Inertia::render('Home', [
            'flux' => $fluxHealth->check(),
            'clusters' => $this->clusters($currentTeam),
            'projects' => $projects,
            'selectedProjectUuid' => $selectedProject['uuid'] ?? null,
            'selectedEnvironmentUuid' => $selectedEnvironment['uuid'] ?? null,
        ]);
    }

    public function coolifyCliVersion(CoolifyCliVersion $coolifyCliVersion): JsonResponse
    {
        return response()->json($coolifyCliVersion->check());
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

    public function bootstrapCoolify(Request $request, CoolifyCliBootstrap $coolifyCliBootstrap): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'ssh_user' => ['required', 'string', 'max:64'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'private_key_uuid' => ['required', 'string'],
            'wg_listen_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'wg_endpoint' => ['nullable', 'string', 'max:255'],
            'enable_builder' => ['boolean'],
            'builder_capacity' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        if (! $currentTeam instanceof Team) {
            abort(403);
        }

        $privateKey = PrivateKey::query()
            ->where('team_id', $currentTeam->id)
            ->where('uuid', $validated['private_key_uuid'])
            ->first();

        if (! $privateKey instanceof PrivateKey) {
            return response()->json([
                'successful' => false,
                'label' => 'Private key unavailable',
                'message' => 'The selected private key is not available for the current team.',
                'output' => null,
                'errorOutput' => null,
                'exitCode' => null,
            ], 403);
        }

        $result = $coolifyCliBootstrap->run($validated, $privateKey);

        if ($result['successful']) {
            $this->recordBootstrappedServer($request->user(), $currentTeam, $privateKey, $validated);
        }

        return response()->json($result, $result['successful'] ? 200 : 500);
    }

    /**
     * @param  array{host: string, ssh_user: string, ssh_port: int, enable_builder?: bool, builder_capacity?: int|null}  $input
     */
    private function recordBootstrappedServer(User $user, Team $team, PrivateKey $privateKey, array $input): void
    {
        $builderEnabled = (bool) ($input['enable_builder'] ?? config('coold.dev_builder_enabled', true));
        $builderCapacity = $builderEnabled ? (int) ($input['builder_capacity'] ?? config('coold.dev_builder_capacity', 2)) : 0;
        $capabilities = $builderEnabled ? ['coold', 'builder'] : ['coold'];

        V5Server::query()->updateOrCreate([
            'team_id' => $team->id,
            'host' => $input['host'],
            'ssh_port' => $input['ssh_port'],
        ], [
            'created_by_user_id' => $user->id,
            'private_key_id' => $privateKey->id,
            'name' => $input['host'],
            'ssh_user' => $input['ssh_user'],
            'status' => 'installed',
            'capabilities' => $capabilities,
            'builder_enabled' => $builderEnabled,
            'builder_capacity' => $builderCapacity,
            'last_bootstrapped_at' => now(),
        ]);
    }

    /**
     * @return array<int, array{id: string, name: string, description: string|null, serversCount: int, servers: array<int, array{id: string, name: string, host: string, status: string, capabilities: array<int, string>}>}>
     */
    private function clusters(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        return V5Cluster::query()
            ->where('team_id', $currentTeam->id)
            ->with(['servers' => fn ($query) => $query->orderBy('name')])
            ->withCount('servers')
            ->orderBy('name')
            ->get()
            ->map(fn (V5Cluster $cluster) => [
                'id' => (string) $cluster->id,
                'name' => $cluster->name,
                'description' => $cluster->description,
                'serversCount' => $cluster->servers_count,
                'servers' => $cluster->servers->map(fn (V5Server $server) => [
                    'id' => (string) $server->id,
                    'name' => $server->name,
                    'host' => $server->host,
                    'status' => $server->status,
                    'capabilities' => $server->capabilities ?? [],
                ])->all(),
            ])
            ->all();
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
