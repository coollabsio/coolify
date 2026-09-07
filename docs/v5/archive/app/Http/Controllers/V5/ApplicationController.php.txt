<?php

namespace App\Http\Controllers\V5;

use App\Actions\V5\Application\DestroyNginxApplication;
use App\Actions\V5\Proxy\StartCaddyIngress;
use App\Enums\V5\ApplicationStatus;
use App\Enums\V5\IngressStatus;
use App\Enums\V5\ServerStatus;
use App\Exceptions\V5\UnsupportedCooldVerb;
use App\Http\Controllers\Controller;
use App\Http\Controllers\V5\Concerns\HandlesIngressSyncErrors;
use App\Http\Controllers\V5\Concerns\ResolvesCurrentTeam;
use App\Http\Controllers\V5\Concerns\ResolvesProjectSelection;
use App\Http\Controllers\V5\Concerns\SerializesCanvasResources;
use App\Jobs\V5DeployApplicationJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ApplicationDomain as V5ApplicationDomain;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Rules\ValidHostname;
use App\Services\Flux\FluxClient;
use App\Support\V5\CanvasResourceSerializer;
use App\Support\V5\ConnectionFirewallSync;
use App\Support\V5\StatusObservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    use HandlesIngressSyncErrors;
    use ResolvesCurrentTeam;
    use ResolvesProjectSelection;
    use SerializesCanvasResources;

    private const DEFAULT_NGINX_IMAGE = 'docker.io/library/nginx:alpine';

    public function __construct(private readonly ConnectionFirewallSync $firewallSync) {}

    public function store(Request $request): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('create', [V5Application::class, $currentTeam]);
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        if ($selectedProject === null || $selectedEnvironment === null) {
            return response()->json([
                'message' => 'Select a project and environment before deploying nginx.',
            ], 422);
        }

        $project = $this->projectQuery($currentTeam)
            ->where('uuid', $selectedProject['uuid'])
            ->first();

        if (! $project instanceof Project) {
            abort(403);
        }

        $environment = $this->selectedEnvironment($project, $selectedEnvironment['uuid']);

        if (! $environment instanceof Environment) {
            abort(403);
        }

        $validated = $request->validate([
            'server_uuid' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._\/:@-]*$/'],
        ]);
        $image = trim($validated['image'] ?? '') ?: self::DEFAULT_NGINX_IMAGE;

        $server = V5Server::query()
            ->where('team_id', $currentTeam->id)
            ->when(
                isset($validated['server_uuid']),
                fn (Builder $query) => $query->where('uuid', $validated['server_uuid']),
                fn (Builder $query) => $query
                    ->orderByRaw('last_bootstrapped_at is null')
                    ->orderBy('name')
            )
            ->first();

        if (! $server instanceof V5Server) {
            return response()->json([
                'message' => 'Add a v5 server before deploying nginx.',
            ], 422);
        }

        if ($server->status !== ServerStatus::Installed->value || $server->last_bootstrapped_at === null) {
            return response()->json([
                'message' => "Bootstrap server {$server->name} before deploying to it.",
            ], 422);
        }

        $canvasPosition = $this->nextApplicationCanvasPosition($currentTeam, $project, $environment);

        $application = V5Application::query()->create([
            'team_id' => $currentTeam->id,
            'project_id' => $project->id,
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'created_by_user_id' => $request->user()->id,
            'name' => 'nginx-test',
            'image' => $image,
            'container_name' => 'coolify-v5-nginx-'.strtolower((string) Str::ulid()),
            'status' => ApplicationStatus::Creating->value,
            'status_message' => 'Starting nginx container.',
            'mesh_namespace' => 'default',
            'canvas_x' => $canvasPosition['canvas_x'],
            'canvas_y' => $canvasPosition['canvas_y'],
        ]);

        V5DeployApplicationJob::dispatch($application->id);

        return response()->json([
            'application' => $this->serializeApplication($application),
        ], 202);
    }

    public function refresh(Request $request, FluxClient $fluxClient): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        if ($selectedProject === null || $selectedEnvironment === null) {
            return response()->json([
                'message' => 'Select a project and environment before refreshing applications.',
            ], 422);
        }

        $applications = $this->applicationQuery($currentTeam, $selectedProject, $selectedEnvironment)
            ->with('server')
            ->get();
        $errors = [];

        $applications
            ->groupBy('server_id')
            ->each(function (Collection $serverApplications) use ($fluxClient, &$errors): void {
                /** @var V5Application|null $firstApplication */
                $firstApplication = $serverApplications->first();
                $server = $firstApplication?->server;
                $hostId = $server?->fluxHostId();

                if (! $server instanceof V5Server || ! is_string($hostId) || $hostId === '') {
                    $errors[] = 'A server is missing its Flux host id.';

                    return;
                }

                // The moment we query coold is the observation time for the rows
                // this refresh writes, so a fresher webhook always wins the
                // status_observed_at watermark and is never clobbered.
                $observedAt = CarbonImmutable::now();

                try {
                    $containers = collect($fluxClient->listContainers($hostId));
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();

                    return;
                }

                $serverApplications->each(function (V5Application $application) use ($containers, $observedAt): void {
                    $container = $containers->first(function (array $container) use ($application): bool {
                        return ($application->runtime_container_id !== null && ($container['id'] ?? null) === $application->runtime_container_id)
                            || ($container['name'] ?? null) === $application->container_name;
                    });

                    if (! is_array($container)) {
                        // A creating application without a container id simply has
                        // not materialized yet; the deploy job will settle it.
                        if ($application->status === ApplicationStatus::Creating->value && $application->runtime_container_id === null) {
                            return;
                        }

                        if (StatusObservation::isStale($observedAt, $application->status_observed_at, 'application status', ['application_id' => $application->id])) {
                            return;
                        }

                        $application->update([
                            'status' => ApplicationStatus::Exited->value,
                            'status_message' => 'Container not found on server.',
                            'status_observed_at' => $observedAt,
                        ]);

                        return;
                    }

                    if (StatusObservation::isStale($observedAt, $application->status_observed_at, 'application status', ['application_id' => $application->id])) {
                        return;
                    }

                    $rawState = is_string($container['state'] ?? null) && $container['state'] !== '' ? $container['state'] : null;

                    $application->update([
                        'status' => StatusObservation::normalize($rawState, ApplicationStatus::class) ?? ApplicationStatus::Unknown->value,
                        'status_message' => 'Container state refreshed from coold.',
                        'status_observed_at' => $observedAt,
                        'runtime_container_id' => is_string($container['id'] ?? null) ? $container['id'] : $application->runtime_container_id,
                    ]);
                });
            });

        V5Server::query()
            ->where('team_id', $currentTeam->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (V5Server $server) => $server->isIngress())
            ->each(function (V5Server $server) use ($fluxClient, &$errors): void {
                $hostId = $server->fluxHostId();

                if (! is_string($hostId) || $hostId === '') {
                    $errors[] = "Caddy ingress server {$server->name} is missing its Flux host id.";

                    return;
                }

                try {
                    $containers = collect($fluxClient->listContainers($hostId));
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();

                    return;
                }

                $container = $containers->first(fn (array $container) => ($container['name'] ?? null) === 'coolify-v5-caddy');
                $rawState = is_array($container) && is_string($container['state'] ?? null) && $container['state'] !== '' ? $container['state'] : null;
                $state = $rawState !== null
                    ? (StatusObservation::normalize($rawState, IngressStatus::class) ?? IngressStatus::Unknown->value)
                    : IngressStatus::Exited->value;

                $server->update([
                    'ingress_type' => 'caddy',
                    'ingress_status' => $state,
                    'last_status_check' => 'flux',
                    'last_status_output' => 'Caddy ingress state refreshed from coold.',
                    'last_status_checked_at' => now(),
                ]);
            });

        return response()->json([
            'applications' => $this->applicationQuery($currentTeam, $selectedProject, $selectedEnvironment)
                ->with('server')
                ->orderBy('created_at')
                ->get()
                ->map(fn (V5Application $application) => $this->serializeApplication($application))
                ->all(),
            'caddyIngresses' => $this->caddyIngresses($currentTeam),
            'errors' => $errors,
        ]);
    }

    public function logs(Request $request, V5Application $application): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('view', [$application, $currentTeam]);

        $application->loadMissing('server');
        $server = $application->server;
        $hostId = $server?->fluxHostId();
        $containerId = $application->runtime_container_id;

        $logs = null;
        $logsError = null;

        // A container id only appears once the deploy actually created one; a
        // deploy that failed before that (e.g. host not connected) has none, so
        // there is nothing to fetch and the frontend just shows the status.
        if (is_string($containerId) && $containerId !== '' && $server instanceof V5Server && $server->status !== ServerStatus::Unreachable->value && is_string($hostId) && $hostId !== '') {
            try {
                $logs = app(FluxClient::class)->containerLogs($hostId, $containerId);
            } catch (UnsupportedCooldVerb $exception) {
                $logsError = "This node's coold does not support container logs.";
            } catch (\RuntimeException $exception) {
                Log::warning('V5 application container logs request failed', [
                    'application_id' => $application->id,
                    'message' => $exception->getMessage(),
                ]);
                $logsError = 'Could not fetch container logs through Flux. Check the Flux and coold status, then try again.';
            }
        }

        return response()->json([
            'status' => $application->status,
            'statusMessage' => $application->status_message,
            'containerId' => $containerId,
            'logs' => $logs,
            'logsError' => $logsError,
        ]);
    }

    public function updatePosition(Request $request, V5Application $application): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('update', [$application, $currentTeam]);

        $validated = $request->validate([
            'canvas_x' => ['required', 'integer', 'min:-100000', 'max:100000'],
            'canvas_y' => ['required', 'integer', 'min:-100000', 'max:100000'],
        ]);

        $application->update([
            'canvas_x' => $validated['canvas_x'],
            'canvas_y' => $validated['canvas_y'],
        ]);

        return response()->json([
            'application' => $this->serializeApplication($application->refresh()->load('server')),
        ]);
    }

    public function updateIngress(Request $request, V5Application $application): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('updateIngress', [$application, $currentTeam]);

        $validated = $request->validate([
            'ingress_enabled' => ['required', 'boolean'],
            'internal_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'domains' => [Rule::requiredIf(fn () => $request->boolean('ingress_enabled')), 'array', 'min:1'],
            'domains.*' => ['required', 'string', 'max:255', 'distinct:ignore_case', new ValidHostname],
        ]);

        $application->loadMissing('server');

        if ($validated['ingress_enabled'] && ! $application->server?->isIngress()) {
            return response()->json([
                'message' => 'Enable ingress on the server before enabling app ingress.',
            ], 422);
        }

        if ($validated['ingress_enabled'] && array_key_exists('domains', $validated)) {
            $conflict = $this->conflictingApplicationDomain($application, $validated['domains']);

            if ($conflict instanceof V5ApplicationDomain) {
                return response()->json([
                    'message' => "The domain {$conflict->domain} is already used by application \"{$conflict->application?->name}\" on this server.",
                ], 422);
            }
        }

        $originalAttributes = $application->only(['ingress_enabled', 'internal_port']);
        $originalDomains = $application->domains()->pluck('domain')->all();

        DB::transaction(function () use ($application, $validated): void {
            $application->update([
                'ingress_enabled' => $validated['ingress_enabled'],
                'internal_port' => $validated['internal_port'] ?? null,
            ]);

            if (array_key_exists('domains', $validated)) {
                $application->domains()->delete();

                collect($validated['domains'])
                    ->map(fn (string $domain) => trim($domain))
                    ->filter()
                    ->unique()
                    ->each(fn (string $domain) => V5ApplicationDomain::query()->create([
                        'application_id' => $application->id,
                        'domain' => $domain,
                    ]));
            }
        });

        $application->refresh()->load(['server', 'domains']);

        if ($application->server?->isIngress() && $application->server->status === ServerStatus::Installed->value) {
            try {
                StartCaddyIngress::run($application->server);
            } catch (\RuntimeException $exception) {
                $this->restoreApplicationIngress($application, $originalAttributes, $originalDomains);

                return $this->ingressSyncErrorResponse($exception);
            }
        }

        return response()->json([
            'application' => $this->serializeApplication($application),
        ]);
    }

    public function updateCaddyIngressPosition(Request $request, V5Server $server): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('updateCanvasPosition', [$server, $currentTeam]);

        $validated = $request->validate([
            'canvas_x' => ['required', 'integer', 'min:-100000', 'max:100000'],
            'canvas_y' => ['required', 'integer', 'min:-100000', 'max:100000'],
        ]);

        $server->update([
            'canvas_x' => $validated['canvas_x'],
            'canvas_y' => $validated['canvas_y'],
        ]);

        return response()->json([
            'caddyIngress' => $this->serializeCaddyIngress($server->refresh()),
        ]);
    }

    public function destroy(Request $request, V5Application $application, FluxClient $fluxClient): Response|JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('delete', [$application, $currentTeam]);

        $application->loadMissing(['server', 'domains']);
        $server = $application->server;
        $connections = $this->applicationResourceConnections($application);

        if ($request->boolean('delete_locally')) {
            $this->deleteApplicationLocally($application, $connections);

            return response()->noContent();
        }
        $oldFirewallRules = $connections
            ->flatMap(function (ResourceConnection $connection): Collection {
                // Deletion must never be blocked by an endpoint that already lost
                // its server; those rules can no longer be revoked anyway.
                try {
                    return $this->firewallSync->rulesFor($connection->load('rules'));
                } catch (\RuntimeException $exception) {
                    report($exception);

                    return collect();
                }
            });
        $originalIngressAttributes = null;
        $originalIngressDomains = [];
        $ingressConfigurationChanged = false;

        try {
            $this->firewallSync->sync($fluxClient, $oldFirewallRules, collect());
        } catch (\RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not sync firewall rules through Flux.',
                'detail' => $exception->getMessage(),
            ], 502);
        }

        if ($server instanceof V5Server && $server->isIngress() && $server->status === ServerStatus::Installed->value && $application->ingress_enabled) {
            $originalIngressAttributes = $application->only(['ingress_enabled', 'internal_port']);
            $originalIngressDomains = $application->domains()->pluck('domain')->all();

            DB::transaction(function () use ($application): void {
                $application->update([
                    'ingress_enabled' => false,
                    'internal_port' => null,
                ]);
                $application->domains()->delete();
            });

            try {
                StartCaddyIngress::run($server);
                $ingressConfigurationChanged = true;
            } catch (\RuntimeException $exception) {
                $this->restoreApplicationIngress($application, $originalIngressAttributes, $originalIngressDomains);

                return $this->ingressSyncErrorResponse($exception);
            }
        }

        $error = DestroyNginxApplication::run($application);

        if ($error !== null) {
            if ($originalIngressAttributes !== null) {
                $this->restoreApplicationIngress($application, $originalIngressAttributes, $originalIngressDomains);

                if ($ingressConfigurationChanged && $server instanceof V5Server) {
                    try {
                        StartCaddyIngress::run($server);
                    } catch (\RuntimeException $exception) {
                        report($exception);
                    }
                }
            }

            try {
                $this->firewallSync->sync($fluxClient, collect(), $oldFirewallRules);
            } catch (\RuntimeException $exception) {
                report($exception);
            }

            return response()->json([
                'message' => $error,
                'can_delete_locally' => true,
            ], 422);
        }

        $this->deleteApplicationLocally($application, $connections);

        return response()->noContent();
    }

    /**
     * @param  Collection<int, ResourceConnection>  $connections
     */
    private function deleteApplicationLocally(V5Application $application, Collection $connections): void
    {
        DB::transaction(function () use ($application, $connections): void {
            $connections->each(function (ResourceConnection $connection): void {
                $connection->rules()->delete();
                $connection->delete();
            });

            $application->delete();
        });
    }

    /**
     * @return Collection<int, ResourceConnection>
     */
    private function applicationResourceConnections(V5Application $application): Collection
    {
        return ResourceConnection::query()
            ->where('team_id', $application->team_id)
            ->where(function (Builder $query) use ($application): void {
                $query
                    ->where(function (Builder $query) use ($application): void {
                        $query
                            ->where('resource_one_type', $application->getMorphClass())
                            ->where('resource_one_id', $application->id);
                    })
                    ->orWhere(function (Builder $query) use ($application): void {
                        $query
                            ->where('resource_two_type', $application->getMorphClass())
                            ->where('resource_two_id', $application->id);
                    });
            })
            ->with('rules')
            ->get();
    }

    /**
     * @return array{canvas_x: int, canvas_y: int}
     */
    private function nextApplicationCanvasPosition(Team $currentTeam, Project $project, Environment $environment): array
    {
        $existingApplications = V5Application::query()
            ->where('team_id', $currentTeam->id)
            ->where('project_id', $project->id)
            ->where('environment_id', $environment->id)
            ->get(['canvas_x', 'canvas_y']);

        $horizontalStep = CanvasResourceSerializer::CARD_WIDTH + CanvasResourceSerializer::CARD_GAP;
        $verticalStep = CanvasResourceSerializer::CARD_HEIGHT + CanvasResourceSerializer::CARD_GAP;

        for ($row = 0; $row < 100; $row++) {
            for ($column = 0; $column < 100; $column++) {
                $candidate = [
                    'canvas_x' => $column * $horizontalStep,
                    'canvas_y' => $row * $verticalStep,
                ];

                if (! $this->canvasPositionCollides($candidate, $existingApplications)) {
                    return $candidate;
                }
            }
        }

        return [
            'canvas_x' => $existingApplications->max('canvas_x') + $horizontalStep,
            'canvas_y' => 0,
        ];
    }

    /**
     * @param  array{canvas_x: int, canvas_y: int}  $candidate
     * @param  Collection<int, V5Application>  $existingApplications
     */
    private function canvasPositionCollides(array $candidate, Collection $existingApplications): bool
    {
        return $existingApplications->contains(function (V5Application $application) use ($candidate) {
            return abs($candidate['canvas_x'] - $application->canvas_x) < CanvasResourceSerializer::CARD_WIDTH + CanvasResourceSerializer::CARD_GAP
                && abs($candidate['canvas_y'] - $application->canvas_y) < CanvasResourceSerializer::CARD_HEIGHT + CanvasResourceSerializer::CARD_GAP;
        });
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function conflictingApplicationDomain(V5Application $application, array $domains): ?V5ApplicationDomain
    {
        $normalizedDomains = collect($domains)
            ->map(fn (string $domain) => Str::lower(trim($domain)))
            ->filter()
            ->values();

        if ($normalizedDomains->isEmpty()) {
            return null;
        }

        return V5ApplicationDomain::query()
            ->whereIn(DB::raw('LOWER(domain)'), $normalizedDomains->all())
            ->whereHas('application', fn (Builder $query) => $query
                ->where('server_id', $application->server_id)
                ->whereKeyNot($application->id)
                ->where('ingress_enabled', true))
            ->with('application:id,name')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $domains
     */
    private function restoreApplicationIngress(V5Application $application, array $attributes, array $domains): void
    {
        DB::transaction(function () use ($application, $attributes, $domains): void {
            $application->update($attributes);
            $application->domains()->delete();

            foreach ($domains as $domain) {
                V5ApplicationDomain::query()->create([
                    'application_id' => $application->id,
                    'domain' => $domain,
                ]);
            }
        });
    }
}
