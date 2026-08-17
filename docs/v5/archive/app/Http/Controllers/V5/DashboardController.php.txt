<?php

namespace App\Http\Controllers\V5;

use App\Events\V5RealtimeTestEvent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\V5\Concerns\ResolvesCurrentTeam;
use App\Http\Controllers\V5\Concerns\ResolvesProjectSelection;
use App\Http\Controllers\V5\Concerns\SerializesCanvasResources;
use App\Models\Project;
use App\Models\Team;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxHealth;
use App\Support\V5\ResourceConnectionSerializer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesCurrentTeam;
    use ResolvesProjectSelection;
    use SerializesCanvasResources;

    public function __construct(private readonly ResourceConnectionSerializer $connectionSerializer) {}

    public function __invoke(Request $request, FluxHealth $fluxHealth): Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);
        $applications = $this->applications($currentTeam, $selectedProject, $selectedEnvironment);
        $requestedApplicationUuid = $request->query('application');
        $selectedApplicationUuid = collect($applications)->contains(
            fn (array $application): bool => $application['id'] === $requestedApplicationUuid
        ) ? $requestedApplicationUuid : null;

        return Inertia::render('Dashboard', [
            'currentTeam' => $this->serializeCurrentTeam($currentTeam),
            'flux' => $fluxHealth->check(),
            'applications' => $applications,
            'caddyIngresses' => $this->caddyIngresses($currentTeam),
            'resourceConnections' => $this->resourceConnections($currentTeam, $selectedProject, $selectedEnvironment),
            'nginxServers' => $this->nginxServers($currentTeam),
            'projects' => $projects,
            'selectedProjectUuid' => $selectedProject['uuid'] ?? null,
            'selectedEnvironmentUuid' => $selectedEnvironment['uuid'] ?? null,
            'selectedApplicationUuid' => $selectedApplicationUuid,
        ]);
    }

    public function realtimeTest(Request $request): Response
    {
        $currentTeam = $this->currentTeamOrFail($request);

        return Inertia::render('RealtimeTest', [
            'currentTeam' => [
                'id' => $currentTeam->id,
            ],
        ]);
    }

    public function broadcastRealtimeTest(Request $request): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        V5RealtimeTestEvent::dispatch(
            $currentTeam->id,
            $validated['message'] ?? 'Manual v5 realtime test'
        );

        return response()->json([
            'message' => 'Realtime test event broadcasted.',
        ], 202);
    }

    public function updateSelection(Request $request): \Illuminate\Http\Response
    {
        $currentTeam = $this->currentTeamOrFail($request);

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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function applications(mixed $currentTeam, ?array $selectedProject, ?array $selectedEnvironment): array
    {
        if (! $currentTeam instanceof Team || $selectedProject === null || $selectedEnvironment === null) {
            return [];
        }

        return $this->applicationQuery($currentTeam, $selectedProject, $selectedEnvironment)
            ->with('server')
            ->orderBy('created_at')
            ->get()
            ->map(fn (V5Application $application) => $this->serializeApplication($application))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resourceConnections(mixed $currentTeam, ?array $selectedProject, ?array $selectedEnvironment): array
    {
        if (! $currentTeam instanceof Team || $selectedProject === null || $selectedEnvironment === null) {
            return [];
        }

        return ResourceConnection::query()
            ->where('team_id', $currentTeam->id)
            ->whereHas('project', fn (Builder $query) => $query
                ->where('team_id', $currentTeam->id)
                ->where('uuid', $selectedProject['uuid']))
            ->whereHas('environment', fn (Builder $query) => $query
                ->where('uuid', $selectedEnvironment['uuid']))
            ->with('rules')
            ->orderBy('id')
            ->get()
            ->map(fn (ResourceConnection $connection) => $this->connectionSerializer->serialize($connection))
            ->all();
    }

    private function nginxServers(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        return V5Server::query()
            ->where('team_id', $currentTeam->id)
            ->orderByRaw('last_bootstrapped_at is null')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'host', 'status'])
            ->map(fn (V5Server $server) => [
                'id' => $server->uuid,
                'name' => $server->name,
                'host' => $server->host,
                'status' => $server->status,
            ])
            ->all();
    }
}
