<?php

namespace App\Http\Controllers\V5;

use App\Events\V5ClusterUpdated;
use App\Events\V5RealtimeTestEvent;
use App\Http\Controllers\Controller;
use App\Jobs\V5BootstrapServerJob;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
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
        ]);

        $builderEnabled = (bool) ($validated['builder_enabled'] ?? $cluster->builder_enabled);
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
            'capabilities' => $builderEnabled ? ['coold', 'builder'] : ['coold'],
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
        ]);

        $builderEnabled = (bool) $validated['builder_enabled'];
        $capabilities = collect($server->capabilities ?? [])
            ->push('coold')
            ->when($builderEnabled, fn ($capabilities) => $capabilities->push('builder'))
            ->when(! $builderEnabled, fn ($capabilities) => $capabilities->reject(fn (string $capability) => $capability === 'builder'))
            ->unique()
            ->values()
            ->all();

        $server->update([
            'capabilities' => $capabilities,
            'builder_enabled' => $builderEnabled,
            'builder_capacity' => (int) $validated['builder_capacity'],
            'builder_cpu_quota' => $validated['builder_cpu_quota'],
        ]);

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
