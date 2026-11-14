<?php

namespace App\Actions\Node;

use App\Enums\NodeContainerSlot;
use App\Enums\NodeWorkloadAction;
use App\Models\NodeOperation;
use App\Models\NodeVolume;
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

    /** Sentinel sets `--dns-search` (decision 0023). */
    public const DNS_SEARCH_CAPABILITY = 'workload.dns_search.v1';

    /** Sentinel writes Compose `content:` files and mounts them (decision 0023). */
    public const FILES_CAPABILITY = 'workload.files.v1';

    /** @return array{command_id: string, observed_at_unix_ms: int, runtime_id: string, name: string, image: string} */
    public function handle(NodeOperation $operation): array
    {
        $operation->loadMissing(['node', 'workload.project', 'workload.environment', 'revision']);
        if ($operation->command_type !== 'workload.deploy.v1' || $operation->workload === null || $operation->revision === null) {
            throw new RuntimeException('The deployment operation is incomplete.');
        }
        // A replayed or late deployment must not recreate a container on a server that lost the placement (failed move, removal).
        if (! $operation->node->workloads()->whereKey($operation->workload->id)->exists()) {
            throw new RuntimeException('The workload is no longer assigned to this server.');
        }

        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
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
        }
        // A Git application deploys its build from the cluster registry; its credential is for the push.
        $login = $operation->workload->buildsFromSource() ? null : IssueNodeRegistryCredential::run($operation->workload, $operation->revision->image);
        if ($login !== null) {
            // An older Sentinel ignores unknown fields and would pull without the login.
            $operation->node->ensureReportedCapability(IssueNodeRegistryCredential::DEPLOY_CAPABILITY);
        }
        if ($healthCheck !== null) {
            // An older Sentinel ignores unknown fields and would start the container without the check.
            $operation->node->ensureReportedCapability('workload.healthcheck.v1');
        }
        $files = $configuration['files'] ?? [];
        if ($files !== []) {
            // An older Sentinel ignores unknown fields and would start the container without its files.
            $operation->node->ensureReportedCapability(self::FILES_CAPABILITY);
        }
        $stack = $operation->workload->stack;
        $dnsSearch = $stack === null || $operation->node->node_cluster_id === null ? [] : [$stack->searchDomain()];
        if ($dnsSearch !== []) {
            // Without the search domain, the services of the stack cannot reach each other by their bare names.
            $operation->node->ensureReportedCapability(self::DNS_SEARCH_CAPABILITY);
        }
        $plan = self::containerPlan($operation, $mounts);
        $containerIp = null;
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
                } else {
                    QueueNodeClusterNetworkRevision::run($operation->node->cluster, $operation->requestedBy);
                }
            }
        }
        $name = $plan['slot']->containerName($operation->workload);
        if ($plan['replaces'] !== null && $operation->node->containers()->where('node_workload_id', $operation->workload->id)->where('name', $name)->exists()) {
            // A leftover of an earlier deploy can still get traffic. Podman would stop it without the drain.
            DispatchWorkloadLifecycle::send($operation->node, "{$operation->uuid}-clear", $name, NodeWorkloadAction::REMOVE);
        }
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(620)
            ->post(rtrim($url, '/').'/v1/commands/workload.deploy', [
                'server_id' => $operation->node->uuid,
                'command_id' => $operation->uuid,
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
                ...($dnsSearch === [] ? [] : ['dns_search' => $dnsSearch]),
                ...($login === null ? [] : ['registry_username' => $login['username'], 'registry_password' => $login['password']]),
                ...($healthCheck === null ? [] : ['health_check' => [
                    'command' => $healthCheck['command'],
                    'interval_seconds' => $healthCheck['interval_seconds'],
                    'timeout_seconds' => $healthCheck['timeout_seconds'],
                    'retries' => $healthCheck['retries'],
                    'start_period_seconds' => $healthCheck['start_period_seconds'],
                ]]),
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
        $rolling = $operation->node->node_cluster_id !== null
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
                ->where('node_workload_id', $operation->workload->id)
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
                    $server = $volume->node?->name ?? 'another server';
                    throw new RuntimeException("The volume {$volume->name} is on server {$server}. Move the application instead.");
                }
            }
        });

        return array_map(fn (array $mount): array => [
            'volume_name' => $mount['volume_name'],
            'container_path' => $mount['container_path'],
            'read_only' => (bool) $mount['read_only'],
        ], $mounts);
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

        return $validated;
    }
}
