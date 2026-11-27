<?php

namespace App\Actions\Node;

use App\Enums\NodeContainerSlot;
use App\Enums\NodeWorkloadAction;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Services\NodeStack\NodeStackComposeMapper;
use App\Support\ContainerIsolation;
use App\Support\NodeRuntimeOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class DispatchWorkloadDeployment
{
    use AsAction;

    /** Sentinel drains a removed container whenever another one serves (decision 0022). */
    public const ROLLING_CAPABILITY = 'workload.rolling_deploy.v1';

    /** A health check command that is a JSON array runs without a shell (the Compose `CMD` form). */
    public const HEALTHCHECK_EXEC_CAPABILITY = 'workload.healthcheck.exec.v1';

    /** Sentinel sets `--dns-search` (decision 0023). */
    public const DNS_SEARCH_CAPABILITY = 'workload.dns_search.v1';

    /** Sentinel writes Compose `content:` files and mounts them (decision 0023). */
    public const FILES_CAPABILITY = 'workload.files.v1';

    /** Sentinel bind-mounts the Podman socket of the server at `container_socket_paths` (decision 0023). */
    public const CONTAINER_SOCKET_CAPABILITY = 'workload.container_socket.v1';

    /** Sentinel runs the container process as `user`, the Compose `user:` (decision 0023). */
    public const USER_CAPABILITY = 'workload.user.v1';

    /** Sentinel sets `shm_size_bytes`, `ulimits`, `tmpfs`, `stop_timeout_seconds`, `tty`, and `stdin_open` (decision 0028). */
    public const RUNTIME_OPTIONS_CAPABILITY = 'workload.runtime_options.v1';

    /** Sentinel sets the typed Docker options and the user labels of decision 0032. */
    public const RUNTIME_OPTIONS_V2_CAPABILITY = 'workload.runtime_options.v2';

    /** Sentinel reports its CDI GPU devices and gives them to a container (decision 0032). */
    public const GPU_CAPABILITY = 'workload.gpu.v1';

    /** Sentinel sets the uid, gid, and mode of a secret file (decision 0008 amendment). */
    public const SECRET_OWNER_CAPABILITY = 'workload.secrets.owner.v1';

    /** @return array{command_id: string, observed_at_unix_ms: int, runtime_id: string, name: string, image: string} */
    public function handle(NodeOperation $operation): array
    {
        $prepared = $this->prepare($operation);
        $plan = $prepared['plan'];
        $name = $prepared['name'];
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }
        if ($plan['replaces'] !== null && $operation->node->containers()->where('node_workload_id', $operation->workload->id)->where('name', $name)->exists()) {
            // A leftover of an earlier deploy can still get traffic. Podman would stop it without the drain.
            DispatchWorkloadLifecycle::send($operation->node, "{$operation->uuid}-clear", $name, NodeWorkloadAction::REMOVE, $operation->revision->stopTimeoutSecondsFor($operation->node));
        }
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(620)
            ->post(rtrim($url, '/').'/v1/commands/workload.deploy', [
                'server_id' => $operation->node->uuid,
                'command_id' => $operation->uuid,
                ...$prepared['payload'],
            ]);
        $response->throw();

        $validator = Validator::make($response->json(), [
            'command_id' => ['required', 'string', 'in:'.$operation->uuid],
            'observed_at_unix_ms' => ['required', 'integer', 'min:1'],
            'runtime_id' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'in:'.$name],
            'image' => ['required', 'string', 'max:2048'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid deployment result.');
        }

        /** @var array{command_id: string, observed_at_unix_ms: int, runtime_id: string, name: string, image: string} */
        return $validator->validated();
    }

    /**
     * Builds the container of a deployment, for `workload.deploy` and for an entry of the desired set
     * that Sentinel converges (decision 0026): it pins the volumes, assigns the workload address, and
     * applies the network revision that a new address needs. Each step is idempotent, so the full set
     * builds it again. `network_revision` is the revision that the container needs before it starts,
     * or 0.
     *
     * @return array{payload: array<string, mixed>, name: string, plan: array{slot: NodeContainerSlot, replaces: ?NodeContainerSlot}, network_revision: int}
     */
    public function prepare(NodeOperation $operation): array
    {
        $operation->loadMissing(['node', 'workload.project', 'workload.environment', 'revision']);
        if ($operation->command_type !== 'workload.deploy.v1' || $operation->workload === null || $operation->revision === null) {
            throw new RuntimeException('The deployment operation is incomplete.');
        }
        // A replayed or late deployment must not recreate a container on a server that lost the placement (failed move, removal).
        if (! $operation->node->workloads()->whereKey($operation->workload->id)->exists()) {
            throw new RuntimeException('The workload is no longer assigned to this server.');
        }

        $configuration = $this->validatedConfiguration([
            ...($operation->revision->configuration ?? []),
            'environment' => $operation->revision->environment ?? [],
        ]);
        $environment = $configuration['environment'] ?? [];
        $resources = $configuration['resources'] ?? [];
        $mounts = $this->pinVolumes($operation, $configuration['volumes'] ?? []);
        $healthCheck = $configuration['health_check'] ?? null;
        $secrets = $operation->revision->secrets ?? [];
        if ($secrets !== []) {
            // An older Sentinel ignores unknown fields and would start the container without its secret files.
            $operation->node->ensureReportedCapability('workload.secrets.v1');
            if ($operation->revision->setsSecretFileOwner()) {
                // An older Sentinel would mount the file as root with mode 0444.
                $operation->node->ensureReportedCapability(self::SECRET_OWNER_CAPABILITY);
            }
        }
        // A Git application deploys its build from the image storage of the cluster; its own credential is for the push.
        $login = $operation->workload->buildsFromSource()
            ? $operation->node->cluster?->imageStorageLogin($operation->revision->image)
            : IssueNodeRegistryCredential::run($operation->workload, $operation->revision->image);
        if ($login !== null) {
            // An older Sentinel ignores unknown fields and would pull without the login.
            $operation->node->ensureReportedCapability(IssueNodeRegistryCredential::DEPLOY_CAPABILITY);
        }
        if ($healthCheck !== null) {
            // An older Sentinel ignores unknown fields and would start the container without the check.
            $operation->node->ensureReportedCapability('workload.healthcheck.v1');
            if (str_starts_with(trim($healthCheck['command']), '[')) {
                // An older Sentinel rejects the exec form of a health check.
                $operation->node->ensureReportedCapability(self::HEALTHCHECK_EXEC_CAPABILITY);
            }
        }
        $files = $configuration['files'] ?? [];
        if ($files !== []) {
            // An older Sentinel ignores unknown fields and would start the container without its files.
            $operation->node->ensureReportedCapability(self::FILES_CAPABILITY);
        }
        $socketPaths = $configuration['container_socket_paths'] ?? [];
        if ($socketPaths !== []) {
            // Flux refuses the field without the capability; check it here for a clear message.
            $operation->node->ensureReportedCapability(self::CONTAINER_SOCKET_CAPABILITY);
        }
        $user = $configuration['user'] ?? '';
        if ($user !== '') {
            // An older Sentinel ignores the field and would start the container as the user of the image.
            $operation->node->ensureReportedCapability(self::USER_CAPABILITY);
        }
        $runtimeOptions = $configuration['runtime_options'] ?? [];
        if ($runtimeOptions !== []) {
            // Flux refuses the fields without the capability; check it here for a clear message.
            $operation->node->ensureReportedCapability(self::RUNTIME_OPTIONS_CAPABILITY);
        }
        $userLabels = $configuration['user_labels'] ?? [];
        if ($userLabels !== [] || array_intersect_key($runtimeOptions, array_flip(NodeRuntimeOptions::V2_KEYS)) !== []) {
            $operation->node->ensureReportedCapability(self::RUNTIME_OPTIONS_V2_CAPABILITY);
        }
        $cdiDevices = self::cdiDevices($operation->node, $configuration['gpu'] ?? []);
        $stack = $operation->workload->stack;
        $dnsSearch = $stack === null || $operation->node->node_cluster_id === null ? [] : [$stack->searchDomain()];
        if ($dnsSearch !== []) {
            // Without the search domain, the services of the stack cannot reach each other by their bare names.
            $operation->node->ensureReportedCapability(self::DNS_SEARCH_CAPABILITY);
        }
        $plan = self::containerPlan($operation, $mounts);
        $containerIp = null;
        $networkRevision = 0;
        if ($operation->node->node_cluster_id !== null) {
            $hadAddress = filled($operation->node->workloads()->whereKey($operation->workload->id)->first()?->pivot->{$plan['slot']->addressColumn()});
            $hadDnsName = filled($operation->workload->internal_dns_name);
            $containerIp = EnsureNodeWorkloadAddress::run($operation->node, $operation->workload, $plan['slot']);
            EnsureNodeWorkloadDnsNames::run($operation->node);
            $operation->workload->refresh();
            if ((! $hadAddress || ! $hadDnsName) && $operation->node->cluster !== null) {
                // A new placement needs its name in the name table (creation assigns the name before any network
                // revision) and, when routed, the firewall allow for this container. A new name needs the name table.
                if ($plan['replaces'] !== null || $stack !== null) {
                    // The new container gets traffic as soon as it is healthy, so its firewall allow must be in place.
                    // The peers of a stack service call it at once, and it calls them.
                    ApplyNodeClusterNetworkRevision::run($operation->node->cluster, $operation->requestedBy);
                    $networkRevision = (int) $operation->node->cluster->fresh()?->desired_revision;
                } else {
                    QueueNodeClusterNetworkRevision::run($operation->node->cluster, $operation->requestedBy);
                }
            }
        }
        $name = $plan['slot']->containerName($operation->workload);
        $payload = [
            'name' => $name,
            'image' => $operation->revision->image,
            'command' => $configuration['command'] ?? [],
            'environment' => $environment === [] ? (object) [] : $environment,
            'ports' => [],
            'labels' => BuildContainerLabels::run($operation->workload, $operation->revision, 'main'),
            'restart_policy' => $configuration['restart_policy'] ?? 'unless-stopped',
            'pull_policy' => data_get($operation->request, 'pull_policy', 'missing'),
            'network_name' => $containerIp === null ? '' : 'coolify-'.$operation->node->uuid,
            'network_subnet' => $operation->node->workload_cidr ?? '',
            'container_ip' => $containerIp ?? '',
            'dns_server' => $containerIp === null ? '' : ($operation->node->wireguard_ip ?? ''),
            'cpu_limit' => $resources['cpu_limit'] ?? null,
            'cpu_reservation' => $resources['cpu_reservation'] ?? null,
            'memory_limit_bytes' => $resources['memory_limit_bytes'] ?? null,
            'memory_reservation_bytes' => $resources['memory_reservation_bytes'] ?? null,
            ...($mounts === [] ? [] : ['mounts' => $mounts]),
            ...($secrets === [] ? [] : ['secrets' => $secrets]),
            ...($files === [] ? [] : ['files' => $files]),
            ...($socketPaths === [] ? [] : ['container_socket_paths' => $socketPaths]),
            ...($user === '' ? [] : ['user' => $user]),
            ...$runtimeOptions,
            ...($userLabels === [] ? [] : ['user_labels' => $userLabels]),
            ...($cdiDevices === [] ? [] : ['cdi_devices' => $cdiDevices]),
            ...($dnsSearch === [] ? [] : ['dns_search' => $dnsSearch]),
            ...($login === null ? [] : ['registry_username' => $login['username'], 'registry_password' => $login['password']]),
            ...($healthCheck === null ? [] : ['health_check' => [
                'command' => $healthCheck['command'],
                'interval_seconds' => $healthCheck['interval_seconds'],
                'timeout_seconds' => $healthCheck['timeout_seconds'],
                'retries' => $healthCheck['retries'],
                'start_period_seconds' => $healthCheck['start_period_seconds'],
            ]]),
        ];

        return ['payload' => $payload, 'name' => $name, 'plan' => $plan, 'network_revision' => $networkRevision];
    }

    /**
     * The CDI names of the GPU request of a revision on a Node. The Node must allow GPU workloads
     * (a team admin turns it on) and report the devices.
     *
     * @param  array{count?: int|string, device_ids?: list<string>}  $gpu
     * @return list<string>
     */
    public static function cdiDevices(Node $node, array $gpu): array
    {
        if ($gpu === []) {
            return [];
        }
        if (! $node->is_gpu_enabled) {
            throw new RuntimeException("The application uses a GPU, and the server {$node->name} does not allow GPU workloads. A team admin can turn on Allow GPU workloads on the server page.");
        }
        $node->ensureReportedCapability(self::GPU_CAPABILITY);

        return NodeRuntimeOptions::cdiDevices($gpu, (array) data_get($node->metadata, 'gpu_cdi_devices', []), $node->name);
    }

    /**
     * The slot that the deploy starts its container in, and the slot of the container that it
     * replaces after the new one is healthy (decision 0022). Without a replaced slot, Podman
     * replaces the container in its slot: the old one stops before the new one starts. A writable
     * volume must never have two writers. The first dispatch stores the plan, so a replay of the
     * same command sends the same container name.
     *
     * @param  list<array{volume_name: string, container_path: string, read_only: bool}>  $mounts
     * @return array{slot: NodeContainerSlot, replaces: ?NodeContainerSlot}
     */
    public static function containerPlan(NodeOperation $operation, array $mounts = []): array
    {
        $stored = data_get($operation->request, 'container');
        if (is_array($stored)) {
            return [
                'slot' => NodeContainerSlot::from($stored['slot']),
                'replaces' => NodeContainerSlot::tryFrom((string) ($stored['replaces'] ?? '')),
            ];
        }

        $current = $operation->workload->containerSlotOn($operation->node);
        // A GPU workload stops before it starts again: two copies often do not fit in the memory of the GPU (decision 0032).
        $rolling = $operation->node->node_cluster_id !== null
            && blank(data_get($operation->revision?->configuration, 'gpu'))
            && $operation->node->supportsCapability(self::ROLLING_CAPABILITY) === true
            && ! collect($mounts)->contains(fn (array $mount): bool => ! $mount['read_only'])
            && $operation->node->containers()
                ->where('node_workload_id', $operation->workload->id)
                ->where('name', $current->containerName($operation->workload))
                ->where('state', 'running')
                ->exists();
        $plan = ['slot' => $rolling ? $current->other() : $current, 'replaces' => $rolling ? $current : null];
        $operation->update(['request' => [
            ...($operation->request ?? []),
            'container' => ['slot' => $plan['slot']->value, 'replaces' => $plan['replaces']?->value],
        ]]);

        return $plan;
    }

    /**
     * Pins every mounted volume to the Node of this deployment: a volume without a Node gets this
     * Node, and a volume on another Node stops the deployment. This is the lowest layer of the
     * one-volume-one-Node rule (decision 0007), so no path can start a second writer.
     *
     * @param  list<array{volume_uuid: string, volume_name: string, container_path: string, read_only: bool}>  $mounts
     * @return list<array{volume_name: string, container_path: string, read_only: bool}>
     */
    private function pinVolumes(NodeOperation $operation, array $mounts): array
    {
        if ($mounts === []) {
            return [];
        }
        // An older Sentinel ignores unknown fields and would start the container without its data.
        $operation->node->ensureReportedCapability('volume.mounts.v1');

        DB::transaction(function () use ($operation, $mounts): void {
            $volumes = NodeVolume::query()
                ->with('node')
                ->where(fn ($query) => $query
                    ->where('node_workload_id', $operation->workload->id)
                    ->orWhereHas('mounts', fn ($mounts) => $mounts->where('node_workload_id', $operation->workload->id)))
                ->whereIn('uuid', array_column($mounts, 'volume_uuid'))
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');
            foreach ($mounts as $mount) {
                $volume = $volumes->get($mount['volume_uuid']);
                if ($volume === null || $volume->podmanName() !== $mount['volume_name'] || CreateNodeVolume::normalizeMountPath($mount['container_path']) !== $mount['container_path']) {
                    throw new RuntimeException("The volume mounted at {$mount['container_path']} no longer exists. Deploy the application again to use its current storage.");
                }
                if ($volume->node_id === null) {
                    $volume->update(['node_id' => $operation->node->id]);
                } elseif ($volume->node_id !== $operation->node->id) {
                    throw new RuntimeException(self::volumeElsewhereMessage($volume, $operation->workload));
                }
            }
        });

        return array_map(fn (array $mount): array => [
            'volume_name' => $mount['volume_name'],
            'container_path' => $mount['container_path'],
            'read_only' => (bool) $mount['read_only'],
        ], $mounts);
    }

    /**
     * Why a workload cannot run on another Node than its volume. A stack volume pins every service
     * that mounts it to its Node (decision 0024).
     */
    public static function volumeElsewhereMessage(NodeVolume $volume, NodeWorkload $workload): string
    {
        $server = $volume->node?->name ?? 'another server';
        if (! $volume->isStackVolume()) {
            return "The volume {$volume->name} is on server {$server}. Move the application instead.";
        }
        $others = $volume->mountingWorkloads()->withoutGlobalScopes()->whereKeyNot($workload->id)->pluck('stack_service')->filter()->implode(', ');

        return "The service {$workload->stack_service} shares the volume {$volume->name}".($others === '' ? '' : " with {$others}")
            ." on server {$server}, so it runs only there. Move the service to move all of them.";
    }

    /** @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function validatedConfiguration(array $configuration): array
    {
        $validator = Validator::make($configuration, [
            'command' => ['sometimes', 'array', 'max:64'],
            'command.*' => ['string', 'max:4096'],
            'environment' => ['sometimes', 'array'],
            'environment.*' => ['string'],
            'restart_policy' => ['sometimes', 'in:no,always,on-failure,unless-stopped'],
            'resources' => ['sometimes', 'array:cpu_limit,cpu_reservation,memory_limit_bytes,memory_reservation_bytes'],
            'resources.cpu_limit' => ['nullable', 'numeric', 'between:0.01,1024'],
            'resources.cpu_reservation' => ['nullable', 'numeric', 'between:0.01,1024'],
            'resources.memory_limit_bytes' => ['nullable', 'integer', 'min:4194304'],
            'resources.memory_reservation_bytes' => ['nullable', 'integer', 'min:4194304'],
            'volumes' => ['sometimes', 'array', 'max:'.NodeVolume::MAX_PER_WORKLOAD],
            'volumes.*.volume_uuid' => ['required', 'string', 'max:64', 'distinct'],
            'volumes.*.volume_name' => ['required', 'string', 'regex:/\\Acoolify-vol-[A-Za-z0-9-]+\\z/'],
            'volumes.*.container_path' => ['required', 'string', 'max:4096', 'distinct'],
            'volumes.*.read_only' => ['required', 'boolean'],
            'files' => ['sometimes', 'array', 'max:64'],
            'files.*' => ['array:path,content_base64,mode,directory'],
            'files.*.path' => ['required', 'string', 'max:4096', 'distinct', 'starts_with:/', 'not_regex:/[,\\x00]/'],
            'files.*.content_base64' => ['present', 'string', 'max:1398104'],
            'files.*.mode' => ['present', 'string', 'regex:/\\A(0?[0-7]{3})?\\z/'],
            'files.*.directory' => ['required', 'boolean'],
            'user' => ['sometimes', 'string', 'regex:'.NodeStackComposeMapper::USER_PATTERN],
            'runtime_options' => ['sometimes', 'array:shm_size_bytes,ulimits,tmpfs,stop_timeout_seconds,tty,stdin_open,'.implode(',', NodeRuntimeOptions::V2_KEYS)],
            'runtime_options.init' => ['sometimes', 'accepted'],
            'runtime_options.read_only' => ['sometimes', 'accepted'],
            'runtime_options.no_new_privileges' => ['sometimes', 'accepted'],
            'runtime_options.pids_limit' => ['sometimes', 'integer', 'between:-1,'.NodeRuntimeOptions::MAX_PIDS_LIMIT, 'not_in:0'],
            'runtime_options.hostname' => ['sometimes', 'string', 'regex:/\\A(?!-)[A-Za-z0-9-]{1,63}(?<!-)\\z/'],
            'runtime_options.entrypoint' => ['sometimes', 'array', 'list', 'min:1', 'max:'.NodeRuntimeOptions::MAX_ENTRYPOINT_WORDS],
            'runtime_options.entrypoint.*' => ['string', 'max:4096'],
            'runtime_options.extra_hosts' => ['sometimes', 'array', 'list', 'max:'.NodeRuntimeOptions::MAX_EXTRA_HOSTS],
            'runtime_options.extra_hosts.*' => ['array:name,ip'],
            'runtime_options.extra_hosts.*.name' => ['required', 'string', 'max:253'],
            'runtime_options.extra_hosts.*.ip' => ['required', 'ip'],
            'runtime_options.cap_add' => ['sometimes', 'array', 'list', 'max:32'],
            'runtime_options.cap_add.*' => ['string', 'distinct', 'in:'.implode(',', [...ContainerIsolation::TIER1_CAP_ADD, ...ContainerIsolation::TIER2_CAP_ADD])],
            'runtime_options.cap_drop' => ['sometimes', 'array', 'list', 'max:64'],
            'runtime_options.cap_drop.*' => ['string', 'distinct', 'in:ALL,'.implode(',', ContainerIsolation::LINUX_CAPABILITIES)],
            'runtime_options.sysctls' => ['sometimes', 'array', 'list', 'max:'.NodeRuntimeOptions::MAX_SYSCTLS],
            'runtime_options.sysctls.*' => ['array:name,value'],
            'runtime_options.sysctls.*.name' => ['required', 'string', 'distinct', 'max:128'],
            'runtime_options.sysctls.*.value' => ['required', 'string', 'regex:'.ContainerIsolation::SYSCTL_VALUE_PATTERN],
            'user_labels' => ['sometimes', 'array', 'list', 'max:'.NodeRuntimeOptions::MAX_LABELS],
            'user_labels.*' => ['array:key,value'],
            'user_labels.*.key' => ['required', 'string', 'distinct', 'regex:#\\A[A-Za-z0-9][A-Za-z0-9._/-]{0,254}\\z#', 'not_regex:/\\A(coolify\\.|io\\.containers\\.|io\\.podman\\.|podman_systemd_unit\\z)/i'],
            'user_labels.*.value' => ['present', 'string', 'max:4096'],
            'gpu' => ['sometimes', 'array:count,device_ids'],
            'gpu.count' => ['sometimes'],
            'gpu.device_ids' => ['sometimes', 'array', 'list', 'max:'.NodeRuntimeOptions::MAX_GPU_DEVICES],
            'gpu.device_ids.*' => ['string', 'max:80'],
            'runtime_options.shm_size_bytes' => ['sometimes', 'integer', 'between:1,'.NodeStackComposeMapper::MAX_RUNTIME_MEMORY_BYTES],
            'runtime_options.ulimits' => ['sometimes', 'array', 'list', 'max:'.NodeStackComposeMapper::MAX_ULIMITS],
            'runtime_options.ulimits.*' => ['array:name,soft,hard'],
            'runtime_options.ulimits.*.name' => ['required', 'string', 'distinct', 'in:'.implode(',', NodeStackComposeMapper::ULIMIT_NAMES)],
            'runtime_options.ulimits.*.soft' => ['required', 'integer', 'min:-1'],
            'runtime_options.ulimits.*.hard' => ['required', 'integer', 'min:-1'],
            'runtime_options.tmpfs' => ['sometimes', 'array', 'list', 'max:'.NodeStackComposeMapper::MAX_TMPFS],
            'runtime_options.tmpfs.*' => ['array:path,size_bytes'],
            'runtime_options.tmpfs.*.path' => ['required', 'string', 'max:4096', 'distinct', 'starts_with:/', 'not_regex:/[,:\\x00]/'],
            'runtime_options.tmpfs.*.size_bytes' => ['required', 'integer', 'between:0,'.NodeStackComposeMapper::MAX_RUNTIME_MEMORY_BYTES],
            'runtime_options.stop_timeout_seconds' => ['sometimes', 'integer', 'between:1,'.NodeStackComposeMapper::MAX_STOP_TIMEOUT_SECONDS],
            'runtime_options.tty' => ['sometimes', 'accepted'],
            'runtime_options.stdin_open' => ['sometimes', 'accepted'],
            'container_socket_paths' => ['sometimes', 'array', 'max:4'],
            'container_socket_paths.*' => ['string', 'max:4096', 'distinct', 'starts_with:/', 'not_regex:/[,:\\x00]/'],
            'health_check' => ['sometimes', 'array:command,description,interval_seconds,timeout_seconds,retries,start_period_seconds'],
            'health_check.command' => ['required_with:health_check', 'string', 'max:4096', 'not_regex:/[\\x00\\r\\n]/'],
            'health_check.description' => ['required_with:health_check', 'string', 'max:4096'],
            'health_check.interval_seconds' => ['required_with:health_check', 'integer', 'between:1,3600'],
            'health_check.timeout_seconds' => ['required_with:health_check', 'integer', 'between:1,3600'],
            'health_check.retries' => ['required_with:health_check', 'integer', 'between:1,100'],
            'health_check.start_period_seconds' => ['required_with:health_check', 'integer', 'between:0,3600'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('The workload revision configuration is invalid.');
        }

        $validated = $validator->validated();
        $resources = $validated['resources'] ?? [];
        if ((isset($resources['cpu_limit'], $resources['cpu_reservation']) && $resources['cpu_reservation'] > $resources['cpu_limit'])
            || (isset($resources['memory_limit_bytes'], $resources['memory_reservation_bytes']) && $resources['memory_reservation_bytes'] > $resources['memory_limit_bytes'])) {
            throw new RuntimeException('The workload revision resource configuration is invalid.');
        }
        // A limit on the Resource Limits page can be lower than a size of the Compose file.
        $memoryLimit = $resources['memory_limit_bytes'] ?? null;
        $sizes = [
            'shm_size' => $validated['runtime_options']['shm_size_bytes'] ?? 0,
            ...collect($validated['runtime_options']['tmpfs'] ?? [])->mapWithKeys(fn (array $tmpfs): array => ["the tmpfs at {$tmpfs['path']}" => $tmpfs['size_bytes']])->all(),
        ];
        foreach ($sizes as $name => $bytes) {
            if ($memoryLimit !== null && $bytes > $memoryLimit) {
                throw new RuntimeException("The size of {$name} is larger than the memory limit of the application. Raise the memory limit on the Resource Limits page.");
            }
        }

        return $validated;
    }
}
