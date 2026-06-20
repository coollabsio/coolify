<?php

namespace App\Http\Controllers\V5;

use App\Actions\V5\Application\DeployNginxApplication;
use App\Actions\V5\Application\DestroyNginxApplication;
use App\Actions\V5\Proxy\StartCaddyIngress;
use App\Actions\V5\Proxy\StopCaddyIngress;
use App\Events\V5ClusterUpdated;
use App\Events\V5RealtimeTestEvent;
use App\Http\Controllers\Controller;
use App\Jobs\V5BootstrapServerJob;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ApplicationDomain as V5ApplicationDomain;
use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use App\Services\Flux\FluxHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const CANVAS_CARD_WIDTH = 320;

    private const CANVAS_CARD_HEIGHT = 144;

    private const CANVAS_CARD_GAP = 32;

    private const SELECTED_PROJECT_SESSION_KEY = 'v5.selectedProjectUuid';

    private const SELECTED_ENVIRONMENT_SESSION_KEY = 'v5.selectedEnvironmentUuid';

    public function __invoke(Request $request, FluxHealth $fluxHealth): Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        return Inertia::render('Dashboard', [
            'currentTeam' => $this->serializeCurrentTeam($currentTeam),
            'flux' => $fluxHealth->check(),
            'applications' => $this->applications($currentTeam, $selectedProject, $selectedEnvironment),
            'caddyIngresses' => $this->caddyIngresses($currentTeam),
            'resourceConnections' => $this->resourceConnections($currentTeam, $selectedProject, $selectedEnvironment),
            'nginxServers' => $this->nginxServers($currentTeam),
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
            'currentTeam' => $this->serializeCurrentTeam($currentTeam),
            'flux' => $fluxHealth->check(),
            'clusters' => $this->clusters($currentTeam),
            'privateKeys' => $this->privateKeys($currentTeam),
            'projects' => $projects,
            'selectedProjectUuid' => $selectedProject['uuid'] ?? null,
            'selectedEnvironmentUuid' => $selectedEnvironment['uuid'] ?? null,
        ]);
    }

    public function showCluster(Request $request, V5Cluster $cluster): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $cluster->team_id !== $currentTeam->id) {
            abort(404);
        }

        return response()->json([
            'cluster' => $this->freshSerializedCluster($cluster),
        ]);
    }

    public function realtimeTest(Request $request): Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team) {
            abort(403);
        }

        return Inertia::render('RealtimeTest', [
            'currentTeam' => [
                'id' => $currentTeam->id,
            ],
        ]);
    }

    public function broadcastRealtimeTest(Request $request): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team) {
            abort(403);
        }

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

    public function storeNginxApplication(Request $request): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team) {
            abort(403);
        }

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
            'server_id' => ['nullable', 'integer'],
        ]);

        $server = V5Server::query()
            ->where('team_id', $currentTeam->id)
            ->when(
                isset($validated['server_id']),
                fn (Builder $query) => $query->whereKey($validated['server_id']),
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

        $canvasPosition = $this->nextApplicationCanvasPosition($currentTeam, $project, $environment);

        $application = V5Application::query()->create([
            'team_id' => $currentTeam->id,
            'project_id' => $project->id,
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'created_by_user_id' => $request->user()->id,
            'name' => 'nginx-test',
            'image' => 'docker.io/library/nginx:alpine',
            'container_name' => 'coolify-v5-nginx-'.strtolower((string) Str::ulid()),
            'status' => 'creating',
            'status_message' => 'Starting nginx container.',
            'mesh_namespace' => 'default',
            'canvas_x' => $canvasPosition['canvas_x'],
            'canvas_y' => $canvasPosition['canvas_y'],
        ]);

        $application = DeployNginxApplication::run($application);

        return response()->json([
            'application' => $this->serializeApplication($application),
        ], $application->status === 'running' ? 201 : 422);
    }

    public function refreshApplications(Request $request, FluxClient $fluxClient): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team) {
            abort(403);
        }

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
                $hostId = $server?->wireguard_management_ip ?: $server?->node_address;

                if (! $server instanceof V5Server || ! is_string($hostId) || $hostId === '') {
                    $errors[] = 'A server is missing its Flux host id.';

                    return;
                }

                try {
                    $containers = collect($fluxClient->listContainers($hostId));
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();

                    return;
                }

                $serverApplications->each(function (V5Application $application) use ($containers): void {
                    $container = $containers->first(function (array $container) use ($application): bool {
                        return ($application->runtime_container_id !== null && ($container['id'] ?? null) === $application->runtime_container_id)
                            || ($container['name'] ?? null) === $application->container_name;
                    });

                    if (! is_array($container)) {
                        $application->update([
                            'status' => 'exited',
                            'status_message' => 'Container not found on server.',
                        ]);

                        return;
                    }

                    $state = is_string($container['state'] ?? null) && $container['state'] !== '' ? $container['state'] : 'unknown';

                    $application->update([
                        'status' => strtolower($state),
                        'status_message' => 'Container state refreshed from coold.',
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
                $hostId = $server->wireguard_management_ip ?: $server->node_address;

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
                $state = is_array($container) && is_string($container['state'] ?? null) && $container['state'] !== ''
                    ? strtolower($container['state'])
                    : 'exited';

                $server->update([
                    'caddy_ingress_status' => $state,
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

    public function updateApplicationPosition(Request $request, V5Application $application): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $application->team_id !== $currentTeam->id) {
            abort(404);
        }

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

    public function updateApplicationIngress(Request $request, V5Application $application): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $application->team_id !== $currentTeam->id) {
            abort(404);
        }

        $validated = $request->validate([
            'ingress_enabled' => ['required', 'boolean'],
            'internal_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'domains' => ['sometimes', 'array'],
            'domains.*' => ['required', 'string', 'max:255', 'distinct'],
        ]);

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

        if ($application->server?->isIngress() && $application->server->status === 'installed') {
            StartCaddyIngress::run($application->server);
        }

        return response()->json([
            'application' => $this->serializeApplication($application),
        ]);
    }

    public function updateCaddyIngressPosition(Request $request, V5Server $server): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $server->team_id !== $currentTeam->id || ! $server->isIngress()) {
            abort(404);
        }

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

    public function destroyApplication(Request $request, V5Application $application): \Illuminate\Http\Response|JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $application->team_id !== $currentTeam->id) {
            abort(404);
        }

        $error = DestroyNginxApplication::run($application);

        if ($error !== null) {
            return response()->json([
                'message' => $error,
            ], 422);
        }

        $application->delete();

        return response()->noContent();
    }

    public function storeResourceConnection(Request $request): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team) {
            abort(403);
        }

        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        if ($selectedProject === null || $selectedEnvironment === null) {
            return response()->json([
                'message' => 'Select a project and environment before connecting resources.',
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
            'resource_one' => ['required', 'array'],
            'resource_one.type' => ['required', 'string', Rule::in(['application'])],
            'resource_one.id' => ['required', 'integer'],
            'resource_two' => ['required', 'array'],
            'resource_two.type' => ['required', 'string', Rule::in(['application'])],
            'resource_two.id' => ['required', 'integer'],
        ]);

        $resourceOne = $this->resolveConnectableResource($currentTeam, $project, $environment, $validated['resource_one']);
        $resourceTwo = $this->resolveConnectableResource($currentTeam, $project, $environment, $validated['resource_two']);

        if ($this->resourceIdentity($resourceOne) === $this->resourceIdentity($resourceTwo)) {
            return response()->json([
                'message' => 'A resource cannot connect to itself.',
            ], 422);
        }

        $connection = ResourceConnection::query()->firstOrCreate(
            [
                'team_id' => $currentTeam->id,
                'resource_pair_key' => $this->resourcePairKey($resourceOne, $resourceTwo),
            ],
            [
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'resource_one_type' => $resourceOne->getMorphClass(),
                'resource_one_id' => $resourceOne->getKey(),
                'resource_two_type' => $resourceTwo->getMorphClass(),
                'resource_two_id' => $resourceTwo->getKey(),
                'created_by_user_id' => $request->user()->id,
            ],
        );

        return response()->json([
            'connection' => $this->serializeResourceConnection($connection->load('rules')),
        ], $connection->wasRecentlyCreated ? 201 : 200);
    }

    public function updateResourceConnection(Request $request, ResourceConnection $connection): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $connection->team_id !== $currentTeam->id) {
            abort(404);
        }

        $validated = $request->validate([
            'ports_by_direction' => ['present', 'array'],
            'ports_by_direction.*' => ['array'],
            'ports_by_direction.*.*' => ['integer', 'min:1', 'max:65535', 'distinct'],
        ]);

        DB::transaction(function () use ($connection, $validated): void {
            $connection->rules()->delete();

            foreach ($validated['ports_by_direction'] as $direction => $ports) {
                [$sourceResourceId, $targetResourceId] = array_pad(explode('->', (string) $direction, 2), 2, null);

                if (! $this->connectionHasResourceId($connection, $sourceResourceId) || ! $this->connectionHasResourceId($connection, $targetResourceId)) {
                    continue;
                }

                foreach (array_unique($ports) as $port) {
                    $connection->rules()->create([
                        'source_resource_type' => $this->resourceTypeForConnectionId($connection, (int) $sourceResourceId),
                        'source_resource_id' => (int) $sourceResourceId,
                        'target_resource_type' => $this->resourceTypeForConnectionId($connection, (int) $targetResourceId),
                        'target_resource_id' => (int) $targetResourceId,
                        'protocol' => 'tcp',
                        'port' => (int) $port,
                    ]);
                }
            }
        });

        return response()->json([
            'connection' => $this->serializeResourceConnection($connection->refresh()->load('rules')),
        ]);
    }

    public function destroyResourceConnection(Request $request, ResourceConnection $connection): \Illuminate\Http\Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $connection->team_id !== $currentTeam->id) {
            abort(404);
        }

        $connection->delete();

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
            'wireguard_interface' => ['sometimes', 'string', 'max:32', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'wireguard_management_pool' => ['sometimes', 'string', 'max:64', $this->ipv4CidrRule()],
            'wireguard_listen_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'container_network_pool' => ['sometimes', 'string', 'max:64', $this->ipv4CidrRule()],
            'container_network_prefix' => ['sometimes', 'integer', 'min:1', 'max:32'],
            'namespaces' => ['sometimes', 'array', 'min:1'],
            'namespaces.*' => ['string', 'distinct', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
            'default_deny_containers' => ['sometimes', 'boolean'],
            'coold_version' => ['sometimes', 'string', 'max:64'],
            'corrosion_version' => ['sometimes', 'string', 'max:64'],
            'corrosion_gossip_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'corrosion_api_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'builder_enabled' => ['sometimes', 'boolean'],
            'builder_capacity' => $this->builderCapacityRules(
                $this->requestedBuilderEnabled($request, true)
            ),
            'builder_cpu_quota' => ['sometimes', 'string', 'max:32'],
            'builder_memory_max' => ['sometimes', 'string', 'max:32'],
            'builder_timeout_secs' => ['sometimes', 'integer', 'min:1', 'max:86400'],
        ]);

        $cluster = V5Cluster::query()->create([
            ...$this->defaultClusterConfiguration(),
            ...collect($validated)->except(['name', 'description'])->all(),
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

    public function bootstrapServer(Request $request, V5Cluster $cluster, V5Server $server): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (
            ! $currentTeam instanceof Team
            || $cluster->team_id !== $currentTeam->id
            || $server->team_id !== $currentTeam->id
            || $server->cluster_id !== $cluster->id
        ) {
            abort(404);
        }

        if ($server->last_bootstrapped_at !== null) {
            return response()->json([
                'message' => 'This server is already bootstrapped.',
            ], 409);
        }

        if (in_array($server->last_bootstrap_status, ['queued', 'running'], true)) {
            return response()->json([
                'cluster' => $this->freshSerializedCluster($cluster),
                'message' => 'Bootstrap is already queued or running for this server.',
            ], 409);
        }

        $installedServers = $cluster->servers()
            ->with('privateKey')
            ->whereNotNull('last_bootstrapped_at')
            ->orderBy('name')
            ->get();
        $server->load('privateKey');
        $servers = $installedServers->toBase()
            ->push($server)
            ->unique('id')
            ->values();

        if ($servers->contains(fn (V5Server $server) => ! $server->privateKey instanceof PrivateKey)) {
            return response()->json([
                'message' => 'The new server and every already-bootstrapped server in this cluster must have a private key before extending the cluster.',
            ], 422);
        }

        $server->update([
            'last_bootstrap_action' => $installedServers->isEmpty() ? 'bootstrap' : 'extend',
            'last_bootstrap_status' => 'queued',
            'last_bootstrap_output' => "Queued Coolify bootstrap for {$server->name}.",
            'last_bootstrap_ran_at' => now(),
        ]);

        V5ClusterUpdated::dispatch($currentTeam->id, $cluster->id);
        V5BootstrapServerJob::dispatch($cluster->id, $server->id);

        return response()->json([
            'cluster' => $this->freshSerializedCluster($cluster),
            'message' => 'Bootstrap queued.',
        ], 202);
    }

    public function storeServer(Request $request, V5Cluster $cluster): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $cluster->team_id !== $currentTeam->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'host' => [
                'required',
                'string',
                'max:255',
                Rule::unique('v5_servers', 'host')
                    ->where('team_id', $currentTeam->id)
                    ->where('ssh_port', (int) $request->input('ssh_port', 22)),
            ],
            'ssh_user' => ['required', 'string', 'max:255'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'private_key_id' => [
                'required',
                'integer',
                Rule::exists('private_keys', 'id')->where('team_id', $currentTeam->id),
            ],
            'node_address' => ['nullable', 'string', 'max:255'],
            'builder_enabled' => ['sometimes', 'boolean'],
            'builder_capacity' => $this->builderCapacityRules(
                $this->requestedBuilderEnabled($request, $cluster->builder_enabled)
            ),
            'builder_cpu_quota' => ['sometimes', 'string', 'max:32'],
            'wireguard_listen_port_override' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'wireguard_endpoint_override' => ['nullable', 'string', 'max:255'],
            'ingress_enabled' => ['sometimes', 'boolean'],
        ]);

        $builderEnabled = (bool) ($validated['builder_enabled'] ?? $cluster->builder_enabled);
        $ingressEnabled = (bool) ($validated['ingress_enabled'] ?? false);
        $builderCapacity = (int) ($validated['builder_capacity'] ?? $cluster->builder_capacity);
        $builderCpuQuota = $validated['builder_cpu_quota'] ?? $cluster->builder_cpu_quota;
        $devWireguardOverrides = $this->devLimaWireguardOverrides($validated['host'], (int) $validated['ssh_port']);

        V5Server::query()->create([
            'team_id' => $currentTeam->id,
            'cluster_id' => $cluster->id,
            'created_by_user_id' => $request->user()->id,
            'name' => $validated['name'],
            'host' => $validated['host'],
            'ssh_user' => $validated['ssh_user'],
            'ssh_port' => $validated['ssh_port'],
            'private_key_id' => $validated['private_key_id'] ?? null,
            'status' => 'added',
            'capabilities' => $this->serverCapabilities($builderEnabled, $ingressEnabled),
            'builder_enabled' => $builderEnabled,
            'builder_capacity' => $builderCapacity,
            'builder_cpu_quota' => $builderCpuQuota,
            'node_address' => $validated['node_address'] ?? $validated['host'],
            'wireguard_listen_port_override' => $validated['wireguard_listen_port_override'] ?? $devWireguardOverrides['listen_port'],
            'wireguard_endpoint_override' => $validated['wireguard_endpoint_override'] ?? $devWireguardOverrides['endpoint'],
        ]);

        $cluster->load(['servers' => fn ($query) => $query
            ->with('privateKey')
            ->orderBy('name')]);
        $cluster->loadCount('servers');

        return response()->json([
            'cluster' => $this->serializeCluster($cluster),
        ], 201);
    }

    public function updateServer(Request $request, V5Cluster $cluster, V5Server $server): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (
            ! $currentTeam instanceof Team
            || $cluster->team_id !== $currentTeam->id
            || $server->team_id !== $currentTeam->id
            || $server->cluster_id !== $cluster->id
        ) {
            abort(404);
        }

        $validated = $request->validate([
            'builder_enabled' => ['required', 'boolean'],
            'builder_capacity' => $this->builderCapacityRules(
                $request->boolean('builder_enabled'),
                required: true
            ),
            'builder_cpu_quota' => ['required', 'string', 'max:32'],
            'ingress_enabled' => ['sometimes', 'boolean'],
        ]);

        $wasIngress = $server->isIngress();
        $builderEnabled = (bool) $validated['builder_enabled'];
        $ingressEnabled = (bool) ($validated['ingress_enabled'] ?? $wasIngress);
        $capabilities = $this->serverCapabilities($builderEnabled, $ingressEnabled);

        $server->update([
            'capabilities' => $capabilities,
            'builder_enabled' => $builderEnabled,
            'builder_capacity' => (int) $validated['builder_capacity'],
            'builder_cpu_quota' => $validated['builder_cpu_quota'],
        ]);

        $server->refresh();
        $this->reconcileCaddyIngress($server, $wasIngress, $ingressEnabled);

        $cluster->load(['servers' => fn ($query) => $query
            ->with('privateKey')
            ->orderBy('name')]);
        $cluster->loadCount('servers');

        return response()->json([
            'cluster' => $this->serializeCluster($cluster),
        ]);
    }

    public function checkServer(Request $request, V5Cluster $cluster, V5Server $server): JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (
            ! $currentTeam instanceof Team
            || $cluster->team_id !== $currentTeam->id
            || $server->team_id !== $currentTeam->id
            || $server->cluster_id !== $cluster->id
        ) {
            abort(404);
        }

        if (! $server->privateKey instanceof PrivateKey) {
            return response()->json([
                'status' => 'failed',
                'output' => 'No private key is attached to this server.',
                'checkedAt' => now()->toJSON(),
            ]);
        }

        $keyDirectory = storage_path('app/ssh/keys');
        if (! is_dir($keyDirectory)) {
            mkdir($keyDirectory, 0700, true);
        }

        $keyLocation = tempnam($keyDirectory, 'v5_ssh_key_');
        if ($keyLocation === false) {
            return response()->json([
                'status' => 'failed',
                'output' => 'Could not create a temporary SSH key file.',
                'checkedAt' => now()->toJSON(),
            ]);
        }

        file_put_contents($keyLocation, $server->privateKey->private_key);
        chmod($keyLocation, 0600);

        $target = "{$server->ssh_user}@{$server->host}";
        $command = [
            'ssh',
            '-o',
            'BatchMode=yes',
            '-o',
            'LogLevel=ERROR',
            '-o',
            'StrictHostKeyChecking=no',
            '-o',
            'UserKnownHostsFile=/dev/null',
            '-o',
            'ConnectTimeout=10',
            '-o',
            'IdentitiesOnly=yes',
            '-i',
            $keyLocation,
            '-p',
            (string) $server->ssh_port,
            $target,
            "printf 'SSH connection OK\n'; hostname; uname -srm; command -v docker || true; command -v podman || true",
        ];

        try {
            $result = Process::timeout(15)->run($command);
            $output = trim($result->output()."\n".$result->errorOutput());
            $status = $result->successful() ? 'reachable' : 'failed';
        } catch (\Throwable $e) {
            $output = $e->getMessage();
            $status = 'failed';
        } finally {
            @unlink($keyLocation);
        }

        return response()->json([
            'status' => $status,
            'output' => str($output !== '' ? $output : 'No output returned.')->limit(10000)->toString(),
            'checkedAt' => now()->toJSON(),
        ]);
    }

    public function destroyServer(Request $request, V5Cluster $cluster, V5Server $server): \Illuminate\Http\Response|JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (
            ! $currentTeam instanceof Team
            || $cluster->team_id !== $currentTeam->id
            || $server->team_id !== $currentTeam->id
            || $server->cluster_id !== $cluster->id
        ) {
            abort(404);
        }

        $server->delete();

        return response()->json([
            'cluster' => $this->freshSerializedCluster($cluster),
        ]);
    }

    public function destroyCluster(Request $request, V5Cluster $cluster): \Illuminate\Http\Response|JsonResponse
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        if (! $currentTeam instanceof Team || $cluster->team_id !== $currentTeam->id) {
            abort(404);
        }

        if ($cluster->servers()->exists()) {
            return response()->json([
                'message' => 'Only empty clusters can be deleted.',
            ], 422);
        }

        $cluster->delete();

        return response()->noContent();
    }

    /**
     * @param  Collection<int, V5Server>  $servers
     * @return array<int, string>
     */
    /**
     * @return array{listen_port: int|null, endpoint: string|null}
     */
    private function devLimaWireguardOverrides(string $host, int $sshPort): array
    {
        if (! app()->environment(['local', 'development', 'testing']) || $host !== 'host.docker.internal') {
            return ['listen_port' => null, 'endpoint' => null];
        }

        if ($sshPort < 60001 || $sshPort > 60009) {
            return ['listen_port' => null, 'endpoint' => null];
        }

        $wireguardPort = $sshPort - 8180;

        return [
            'listen_port' => $wireguardPort,
            'endpoint' => "host.lima.internal:{$wireguardPort}",
        ];
    }

    private function bootstrapCommand(V5Cluster $cluster, Collection $servers, V5Server $newServer, string $sshConfigLocation, string $action): array
    {
        $command = [
            $this->coolifyCliBin(),
            'init',
            $action,
            '--format',
            'table',
            '--nodes',
            $servers->map(fn (V5Server $server) => $this->bootstrapNode($server))->implode(','),
            '--ssh-config',
            $sshConfigLocation,
            '--namespaces',
            implode(',', $cluster->namespaces ?? V5Cluster::DEFAULT_NAMESPACES),
            '--container-pool',
            $cluster->container_network_pool,
            '--container-prefix',
            (string) $cluster->container_network_prefix,
            '--wg-mgmt-pool',
            $cluster->wireguard_management_pool,
            '--wg-interface',
            $cluster->wireguard_interface,
            '--wg-listen-port',
            (string) $cluster->wireguard_listen_port,
            '--coold-version',
            $cluster->coold_version,
            '--corrosion-version',
            $cluster->corrosion_version,
            '--corrosion-gossip-port',
            (string) $cluster->corrosion_gossip_port,
            '--corrosion-api-port',
            (string) $cluster->corrosion_api_port,
        ];

        if ($action === 'extend') {
            array_push($command, '--new-nodes', $this->bootstrapNode($newServer));
        }

        $listenOverrides = $this->wireguardListenPortOverrides($servers);
        if ($listenOverrides !== '') {
            array_push($command, '--wg-listen-port-overrides', $listenOverrides);
        }

        $endpointOverrides = $this->wireguardEndpointOverrides($servers);
        if ($endpointOverrides !== '') {
            array_push($command, '--wg-endpoint-overrides', $endpointOverrides);
        }

        if (! $cluster->default_deny_containers) {
            $command[] = '--skip-default-deny';
        }

        $builderServers = $servers->filter(fn (V5Server $server) => $server->builder_enabled);
        if ($cluster->builder_enabled && $builderServers->isNotEmpty()) {
            array_push(
                $command,
                '--enable-builder',
                '--builder-hosts',
                $builderServers
                    ->map(fn (V5Server $server) => $this->bootstrapNode($server))
                    ->implode(','),
                '--builder-capacity',
                (string) $cluster->builder_capacity,
                '--builder-cpu-quota',
                $cluster->builder_cpu_quota,
                '--builder-memory-max',
                $cluster->builder_memory_max,
                '--builder-timeout-secs',
                (string) $cluster->builder_timeout_secs,
            );
        }

        $command[] = '--yes';

        return $command;
    }

    private function coolifyCliBin(): string
    {
        $configuredBinary = (string) config('coold.coolify_cli_bin', '/usr/local/bin/coolify');
        $devBinary = base_path('.dev/bin/coolify');

        if ($configuredBinary === '/usr/local/bin/coolify' && $this->isRunnableDevelopmentCliBinary($devBinary)) {
            return $devBinary;
        }

        return $configuredBinary;
    }

    private function isRunnableDevelopmentCliBinary(string $binary): bool
    {
        if (! is_file($binary)) {
            return false;
        }

        $header = file_get_contents($binary, false, null, 0, 4);

        if ($header === false) {
            return false;
        }

        if (str_starts_with($header, '#!')) {
            return true;
        }

        if ($header === "\x7FELF") {
            return true;
        }

        return false;
    }

    private function bootstrapNode(V5Server $server): string
    {
        return "v5-server-{$server->id}";
    }

    /**
     * @param  Collection<int, V5Server>  $servers
     */
    private function writeBootstrapSshConfig(Collection $servers, string $tempDirectory): string
    {
        $config = '';

        $servers->each(function (V5Server $server) use (&$config, $tempDirectory): void {
            $keyLocation = "{$tempDirectory}/server-{$server->id}.key";
            file_put_contents($keyLocation, $server->privateKey->private_key);
            chmod($keyLocation, 0600);

            $config .= implode("\n", [
                'Host '.$this->bootstrapNode($server),
                '  HostName '.$server->host,
                '  Port '.$server->ssh_port,
                '  User '.$server->ssh_user,
                '  IdentityFile '.$keyLocation,
                '  IdentitiesOnly yes',
                '  LogLevel ERROR',
                '  StrictHostKeyChecking no',
                '  UserKnownHostsFile /dev/null',
                '  BatchMode yes',
                '',
            ]);
        });

        $sshConfigLocation = "{$tempDirectory}/ssh.config";
        file_put_contents($sshConfigLocation, $config);
        chmod($sshConfigLocation, 0600);

        return $sshConfigLocation;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = "{$directory}/{$file}";

            if (is_dir($path)) {
                $this->deleteDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * @param  Collection<int, V5Server>  $servers
     */
    private function wireguardListenPortOverrides(Collection $servers): string
    {
        return $servers
            ->filter(fn (V5Server $server) => $server->wireguard_listen_port_override !== null)
            ->map(fn (V5Server $server) => $this->bootstrapNode($server).'='.$server->wireguard_listen_port_override)
            ->implode(',');
    }

    /**
     * @param  Collection<int, V5Server>  $servers
     */
    private function wireguardEndpointOverrides(Collection $servers): string
    {
        return $servers
            ->filter(fn (V5Server $server) => $server->wireguard_endpoint_override !== null)
            ->map(fn (V5Server $server) => $this->bootstrapNode($server).'='.$server->wireguard_endpoint_override)
            ->implode(',');
    }

    /**
     * @return array<int, array{id: string, name: string, host: string, status: string}>
     */
    /**
     * @return array{id: int}|null
     */
    private function serializeCurrentTeam(mixed $currentTeam): ?array
    {
        if (! $currentTeam instanceof Team) {
            return null;
        }

        return [
            'id' => $currentTeam->id,
        ];
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
            ->get(['id', 'name', 'host', 'status'])
            ->map(fn (V5Server $server) => [
                'id' => (string) $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'status' => $server->status,
            ])
            ->all();
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
    private function caddyIngresses(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        return V5Server::query()
            ->where('team_id', $currentTeam->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (V5Server $server) => $server->isIngress())
            ->values()
            ->map(fn (V5Server $server, int $index) => $this->serializeCaddyIngress($server, $index))
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
            ->map(fn (ResourceConnection $connection) => $this->serializeResourceConnection($connection))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCaddyIngress(V5Server $server, int $index = 0): array
    {
        return [
            'id' => (string) $server->id,
            'name' => $server->name,
            'host' => $server->host,
            'status' => $server->caddyIngressStatus(),
            'canvasX' => $server->canvas_x ?? -self::CANVAS_CARD_WIDTH - self::CANVAS_CARD_GAP,
            'canvasY' => $server->canvas_y ?? $index * (self::CANVAS_CARD_HEIGHT + self::CANVAS_CARD_GAP),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeResourceConnection(ResourceConnection $connection): array
    {
        return [
            'id' => (string) $connection->id,
            'applicationIds' => [
                (string) $connection->resource_one_id,
                (string) $connection->resource_two_id,
            ],
            'fromApplicationId' => (string) $connection->resource_one_id,
            'toApplicationId' => (string) $connection->resource_two_id,
            'portsByDirection' => $connection->rules
                ->groupBy(fn ($rule) => "{$rule->source_resource_id}->{$rule->target_resource_id}")
                ->map(fn (Collection $rules) => $rules
                    ->sortBy('port')
                    ->pluck('port')
                    ->map(fn ($port) => (string) $port)
                    ->values()
                    ->all())
                ->all(),
        ];
    }

    /**
     * @param  array{type: string, id: int}  $resource
     */
    private function resolveConnectableResource(Team $team, Project $project, Environment $environment, array $resource): Model
    {
        return match ($resource['type']) {
            'application' => V5Application::query()
                ->where('team_id', $team->id)
                ->where('project_id', $project->id)
                ->where('environment_id', $environment->id)
                ->whereKey($resource['id'])
                ->firstOrFail(),
        };
    }

    private function resourcePairKey(Model $resourceOne, Model $resourceTwo): string
    {
        return collect([
            $this->resourceIdentity($resourceOne),
            $this->resourceIdentity($resourceTwo),
        ])->sort()->implode('|');
    }

    private function resourceIdentity(Model $resource): string
    {
        return $resource->getMorphClass().':'.$resource->getKey();
    }

    private function connectionHasResourceId(ResourceConnection $connection, mixed $resourceId): bool
    {
        return in_array((int) $resourceId, [
            (int) $connection->resource_one_id,
            (int) $connection->resource_two_id,
        ], true);
    }

    private function resourceTypeForConnectionId(ResourceConnection $connection, int $resourceId): string
    {
        return (int) $connection->resource_one_id === $resourceId
            ? $connection->resource_one_type
            : $connection->resource_two_type;
    }

    /**
     * @param  array{uuid: string}  $selectedProject
     * @param  array{uuid: string}  $selectedEnvironment
     * @return Builder<V5Application>
     */
    private function applicationQuery(Team $currentTeam, array $selectedProject, array $selectedEnvironment): Builder
    {
        return V5Application::query()
            ->where('team_id', $currentTeam->id)
            ->whereHas('project', fn (Builder $query) => $query
                ->where('team_id', $currentTeam->id)
                ->where('uuid', $selectedProject['uuid']))
            ->whereHas('environment', fn (Builder $query) => $query
                ->where('uuid', $selectedEnvironment['uuid']));
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

        $horizontalStep = self::CANVAS_CARD_WIDTH + self::CANVAS_CARD_GAP;
        $verticalStep = self::CANVAS_CARD_HEIGHT + self::CANVAS_CARD_GAP;

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
            return abs($candidate['canvas_x'] - $application->canvas_x) < self::CANVAS_CARD_WIDTH + self::CANVAS_CARD_GAP
                && abs($candidate['canvas_y'] - $application->canvas_y) < self::CANVAS_CARD_HEIGHT + self::CANVAS_CARD_GAP;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApplication(V5Application $application): array
    {
        $application->loadMissing(['server', 'domains']);

        return [
            'id' => (string) $application->id,
            'name' => $application->name,
            'image' => $application->image,
            'containerName' => $application->container_name,
            'status' => $application->status,
            'statusMessage' => $application->status_message,
            'runtimeContainerId' => $application->runtime_container_id,
            'serverName' => $application->server?->name,
            'meshNamespace' => $application->mesh_namespace,
            'ingressEnabled' => $application->ingress_enabled,
            'internalPort' => $application->internal_port,
            'domains' => $application->domains->pluck('domain')->values()->all(),
            'meshFqdn' => $application->container_name.'.'.($application->mesh_namespace ?: 'default').'.coolify.internal',
            'canvasX' => $application->canvas_x,
            'canvasY' => $application->canvas_y,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
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
     * @return array<int, array{id: string, name: string}>
     */
    private function privateKeys(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        return PrivateKey::query()
            ->where('team_id', $currentTeam->id)
            ->where('is_git_related', false)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PrivateKey $privateKey) => [
                'id' => (string) $privateKey->id,
                'name' => $privateKey->name,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function serverCapabilities(bool $builderEnabled, bool $ingressEnabled): array
    {
        return collect(['coold'])
            ->when($builderEnabled, fn ($capabilities) => $capabilities->push('builder'))
            ->when($ingressEnabled, fn ($capabilities) => $capabilities->push('ingress'))
            ->unique()
            ->values()
            ->all();
    }

    private function reconcileCaddyIngress(V5Server $server, bool $wasIngress, bool $isIngress): void
    {
        if ($server->status !== 'installed') {
            return;
        }

        if (! $wasIngress && $isIngress) {
            StartCaddyIngress::run($server);

            return;
        }

        if ($wasIngress && ! $isIngress) {
            StopCaddyIngress::run($server);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCluster(V5Cluster $cluster): array
    {
        return [
            'id' => (string) $cluster->id,
            'name' => $cluster->name,
            'description' => $cluster->description,
            'wireguardInterface' => $cluster->wireguard_interface,
            'wireguardManagementPool' => $cluster->wireguard_management_pool,
            'wireguardListenPort' => $cluster->wireguard_listen_port,
            'containerNetworkPool' => $cluster->container_network_pool,
            'containerNetworkPrefix' => $cluster->container_network_prefix,
            'namespaces' => $cluster->namespaces ?? V5Cluster::DEFAULT_NAMESPACES,
            'defaultDenyContainers' => $cluster->default_deny_containers,
            'cooldVersion' => $cluster->coold_version,
            'corrosionVersion' => $cluster->corrosion_version,
            'corrosionGossipPort' => $cluster->corrosion_gossip_port,
            'corrosionApiPort' => $cluster->corrosion_api_port,
            'builderEnabled' => $cluster->builder_enabled,
            'builderCapacity' => $cluster->builder_capacity,
            'builderCpuQuota' => $cluster->builder_cpu_quota,
            'builderMemoryMax' => $cluster->builder_memory_max,
            'builderTimeoutSecs' => $cluster->builder_timeout_secs,
            'lastCliAction' => $cluster->last_cli_action,
            'lastCliStatus' => $cluster->last_cli_status,
            'lastCliSummary' => $cluster->last_cli_summary,
            'lastCliRanAt' => $cluster->last_cli_ran_at?->toJSON(),
            'serversCount' => $cluster->servers_count ?? $cluster->servers->count(),
            'servers' => $cluster->servers->map(fn (V5Server $server) => [
                'id' => (string) $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'status' => $server->status,
                'capabilities' => $server->capabilities ?? [],
                'builderEnabled' => $server->builder_enabled,
                'builderCapacity' => $server->builder_capacity,
                'builderCpuQuota' => $server->builder_cpu_quota,
                'ingressEnabled' => $server->isIngress(),
                'uuid' => $server->uuid,
                'nodeAddress' => $server->node_address,
                'wireguardListenPortOverride' => $server->wireguard_listen_port_override,
                'wireguardEndpointOverride' => $server->wireguard_endpoint_override,
                'wireguardManagementIp' => $server->wireguard_management_ip,
                'wireguardPublicKey' => $server->wireguard_public_key,
                'containerSubnets' => $server->container_subnets ?? [],
                'privateKeyName' => $server->privateKey?->name,
                'lastBootstrappedAt' => $server->last_bootstrapped_at?->toJSON(),
                'lastBootstrapAction' => $server->last_bootstrap_action,
                'lastBootstrapStatus' => $server->last_bootstrap_status,
                'lastBootstrapOutput' => $server->last_bootstrap_output,
                'lastBootstrapRanAt' => $server->last_bootstrap_ran_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function freshSerializedCluster(V5Cluster $cluster): array
    {
        $cluster->load(['servers' => fn ($query) => $query
            ->with('privateKey')
            ->orderBy('name')]);
        $cluster->loadCount('servers');

        return $this->serializeCluster($cluster);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultClusterConfiguration(): array
    {
        return [
            'wireguard_interface' => V5Cluster::DEFAULT_WIREGUARD_INTERFACE,
            'wireguard_management_pool' => V5Cluster::DEFAULT_WIREGUARD_MANAGEMENT_POOL,
            'wireguard_listen_port' => V5Cluster::DEFAULT_WIREGUARD_LISTEN_PORT,
            'container_network_pool' => V5Cluster::DEFAULT_CONTAINER_NETWORK_POOL,
            'container_network_prefix' => V5Cluster::DEFAULT_CONTAINER_NETWORK_PREFIX,
            'namespaces' => V5Cluster::DEFAULT_NAMESPACES,
            'default_deny_containers' => true,
            'coold_version' => V5Cluster::DEFAULT_COOLD_VERSION,
            'corrosion_version' => V5Cluster::DEFAULT_CORROSION_VERSION,
            'corrosion_gossip_port' => V5Cluster::DEFAULT_CORROSION_GOSSIP_PORT,
            'corrosion_api_port' => V5Cluster::DEFAULT_CORROSION_API_PORT,
            'builder_enabled' => true,
            'builder_capacity' => V5Cluster::DEFAULT_BUILDER_CAPACITY,
            'builder_cpu_quota' => V5Cluster::DEFAULT_BUILDER_CPU_QUOTA,
            'builder_memory_max' => V5Cluster::DEFAULT_BUILDER_MEMORY_MAX,
            'builder_timeout_secs' => V5Cluster::DEFAULT_BUILDER_TIMEOUT_SECS,
        ];
    }

    private function ipv4CidrRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! str_contains($value, '/')) {
                $fail('The :attribute must be a valid IPv4 CIDR range.');

                return;
            }

            [$ip, $prefix] = explode('/', $value, 2);

            if (
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || ! ctype_digit($prefix)
                || (int) $prefix < 0
                || (int) $prefix > 32
            ) {
                $fail('The :attribute must be a valid IPv4 CIDR range.');
            }
        };
    }

    /**
     * @return array<int, string>
     */
    private function builderCapacityRules(bool $builderEnabled, bool $required = false): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'integer',
            $builderEnabled ? 'min:1' : 'min:0',
            'max:1000',
        ];
    }

    private function requestedBuilderEnabled(Request $request, bool $default): bool
    {
        if (! $request->has('builder_enabled')) {
            return $default;
        }

        return $request->boolean('builder_enabled');
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
