<?php

namespace App\Livewire\NodeCluster;

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\Build\InspectNodeRegistry;
use App\Actions\Node\DeleteNodeCluster;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\FetchLatestSentinelRelease;
use App\Actions\Node\ManageNodeClusterSettings;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\RepairNodeClusterNetwork;
use App\Actions\Node\SetNodeBuilder;
use App\Actions\Node\SetNodeIngress;
use App\Actions\Node\UpgradeSentinel;
use App\Enums\NodeWorkloadState;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Jobs\UpgradeClusterSentinelJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\RegistryCredential;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

class Show extends Component
{
    use AuthorizesRequests;

    private const SECTIONS_BY_ROUTE = [
        'node-cluster.show' => 'general',
        'node-cluster.resources' => 'resources',
        'node-cluster.nodes' => 'nodes',
        'node-cluster.firewall' => 'firewall',
        'node-cluster.advanced' => 'advanced',
        'node-cluster.delete' => 'danger',
    ];

    public NodeCluster $cluster;

    /** @var array<string, mixed>|null The image registry report; loaded after the page renders. */
    public ?array $registryReport = null;

    #[Locked]
    public string $section = 'general';

    #[Url(as: 'search', except: '')]
    public string $resourceSearch = '';

    public string $name = '';

    public string $description = '';

    public string $cidr = '';

    public string $wireguardInterface = 'coolify0';

    public int $wireguardPort = 51820;

    public int $cpuPressureThreshold = 95;

    public int $memoryPressureThreshold = 90;

    public int $diskPressureThreshold = 90;

    public int $resourceStaleAfterMinutes = 5;

    /** `external`, `built_in`, or empty while the user has not chosen. */
    public string $imageStorage = '';

    public string $imageRepository = '';

    public string $imageRegistryCredentialUuid = '';

    public string $nodeUuid = '';

    public string $firewallSourceUuid = '';

    public string $firewallDestinationUuid = '';

    public string $firewallProtocol = 'tcp';

    public int $firewallPort = 80;

    public function mount(string $cluster_uuid): void
    {
        abort_unless(nodesEnabled(), 404);
        $this->cluster = NodeCluster::query()->where('team_id', currentTeam()->id)->where('uuid', $cluster_uuid)->firstOrFail();
        $this->authorize('view', $this->cluster);
        $this->section = self::SECTIONS_BY_ROUTE[request()->route()?->getName()] ?? 'general';
        $this->fillFromCluster();
    }

    public function saveSettings(): void
    {
        $this->authorize('update', $this->cluster);
        $this->resetErrorBag();
        try {
            $this->cluster = ManageNodeClusterSettings::make()->updateSettings($this->cluster, [
                'name' => $this->name,
                'description' => $this->description,
                'cidr' => $this->cidr,
                'wireguard_interface' => $this->wireguardInterface,
                'wireguard_port' => $this->wireguardPort,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $this->addErrors($exception, ['wireguard_interface' => 'wireguardInterface', 'wireguard_port' => 'wireguardPort']);

            return;
        }
        $this->fillFromCluster();
        $this->dispatch('success', 'Cluster settings saved.');
    }

    public function assignNode(): void
    {
        $this->authorize('update', $this->cluster);
        $this->validate(['nodeUuid' => ['required', 'string']], [], ['nodeUuid' => 'server']);
        $node = Node::query()->where('team_id', $this->cluster->team_id)->whereNull('node_cluster_id')->where('uuid', $this->nodeUuid)->firstOrFail();
        try {
            AssignNodeToCluster::run($this->cluster, $node, auth()->user());
        } catch (DomainException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $this->cluster->refresh();
        $this->reset('nodeUuid');
        $this->dispatch('close-modal');
        $this->dispatch('success', 'Server assigned to the cluster.');
    }

    public function savePressurePolicy(): void
    {
        $this->authorize('update', $this->cluster);
        $this->resetErrorBag();
        try {
            $this->cluster = ManageNodeClusterSettings::make()->updatePressurePolicy($this->cluster, [
                'cpu_pressure_threshold' => $this->cpuPressureThreshold,
                'memory_pressure_threshold' => $this->memoryPressureThreshold,
                'disk_pressure_threshold' => $this->diskPressureThreshold,
                'resource_stale_after_minutes' => $this->resourceStaleAfterMinutes,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $this->addErrors($exception, [
                'cpu_pressure_threshold' => 'cpuPressureThreshold',
                'memory_pressure_threshold' => 'memoryPressureThreshold',
                'disk_pressure_threshold' => 'diskPressureThreshold',
                'resource_stale_after_minutes' => 'resourceStaleAfterMinutes',
            ]);

            return;
        }
        $this->fillFromCluster();
        $this->dispatch('success', 'Deployment pressure policy saved.');
    }

    public function removeNode(string $nodeUuid): void
    {
        $this->authorize('update', $this->cluster);
        $node = Node::query()->where('team_id', $this->cluster->team_id)->where('node_cluster_id', $this->cluster->id)->where('uuid', $nodeUuid)->firstOrFail();
        try {
            RemoveNodeFromCluster::run($this->cluster, $node, auth()->user());
        } catch (DomainException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $this->cluster->refresh();
        $this->dispatch('success', 'Server removed from the cluster.');
    }

    public function setIngress(string $nodeUuid, bool $enabled): void
    {
        $this->authorize('update', $this->cluster);
        $node = Node::query()->where('team_id', $this->cluster->team_id)->where('node_cluster_id', $this->cluster->id)->where('uuid', $nodeUuid)->firstOrFail();
        try {
            SetNodeIngress::run($this->cluster, $node, $enabled, auth()->user());
        } catch (DomainException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $this->cluster->refresh();
        $this->dispatch('success', $enabled ? "Ingress turned on for {$node->name}." : "Ingress turned off for {$node->name}.");
    }

    public function setBuilder(string $nodeUuid, bool $enabled): void
    {
        $this->authorize('update', $this->cluster);
        $node = Node::query()->where('team_id', $this->cluster->team_id)->where('node_cluster_id', $this->cluster->id)->where('uuid', $nodeUuid)->firstOrFail();
        try {
            SetNodeBuilder::run($this->cluster, $node, $enabled, auth()->user());
        } catch (DomainException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $this->cluster->refresh();
        $this->dispatch('success', $enabled ? "Builds turned on for {$node->name}." : "Builds turned off for {$node->name}.");
    }

    /** Loads the image registry report from the Nodes. The Image registry section calls it on load. */
    public function loadRegistryReport(): void
    {
        $this->authorize('view', $this->cluster);
        if ($this->cluster->registry === null) {
            return;
        }
        $this->registryReport = InspectNodeRegistry::run($this->cluster);
    }

    /** Selects the Node that runs the image registry, or clears the selection for automatic. */
    public function setRegistryNode(string $nodeUuid = ''): void
    {
        $this->authorize('update', $this->cluster);
        $node = $nodeUuid === '' ? null : Node::query()
            ->where('team_id', $this->cluster->team_id)
            ->where('node_cluster_id', $this->cluster->id)
            ->where('uuid', $nodeUuid)
            ->firstOrFail();
        try {
            ManageNodeClusterSettings::make()->setRegistryNode($this->cluster, $node, auth()->user());
        } catch (RuntimeException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $this->dispatch('success', $node === null
            ? 'The registry runs on the first available server.'
            : 'The registry moves to the selected server. Images stored on the previous server are rebuilt on the next deploy.');
    }

    /**
     * Saves where the builds of this cluster are stored. A change turns the built-in registry on
     * or off; images in the previous storage are not copied.
     */
    public function saveImageStorage(): void
    {
        $this->authorize('update', $this->cluster);
        $this->resetErrorBag();
        try {
            $changed = ManageNodeClusterSettings::make()->updateImageStorage($this->cluster, $this->imageStorage, $this->imageRepository, $this->imageRegistryCredentialUuid, auth()->user());
        } catch (ValidationException $exception) {
            $this->addErrors($exception, ['image_storage' => 'imageStorage', 'image_repository' => 'imageRepository', 'image_registry_credential_uuid' => 'imageRegistryCredentialUuid']);

            return;
        }
        $this->cluster->refresh();
        $this->fillFromCluster();
        $this->dispatch('success', $changed
            ? 'Image storage saved. Deploy each Git application again, so that its image is in the new storage.'
            : 'Image storage saved.');
    }

    public function deleteCluster(mixed $password = null): bool|string
    {
        $this->authorize('delete', $this->cluster);
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }
        try {
            DeleteNodeCluster::run($this->cluster, auth()->user());
        } catch (DomainException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return true;
        }
        $this->redirectRoute('server.index', navigate: true);

        return true;
    }

    /**
     * Clears the destination when it is the newly selected source application.
     */
    public function updatedFirewallSourceUuid(string $value): void
    {
        if ($value === 'workload:'.$this->firewallDestinationUuid) {
            $this->reset('firewallDestinationUuid');
        }
    }

    public function addFirewallRule(): void
    {
        $this->authorize('update', $this->cluster);
        if ($this->firewallProtocol === 'icmp') {
            $this->firewallPort = 0;
        }
        $validated = $this->validate([
            'firewallSourceUuid' => ['required', 'string'],
            'firewallDestinationUuid' => ['required', 'string'],
            'firewallProtocol' => ['required', Rule::in(['tcp', 'udp', 'icmp'])],
            'firewallPort' => $this->firewallProtocol === 'icmp'
                ? ['required', 'integer', 'in:0']
                : ['required', 'integer', 'between:1,65535'],
        ]);

        [$sourceType, $sourceUuid] = array_pad(explode(':', $validated['firewallSourceUuid'], 2), 2, '');
        $this->createFirewallRule(
            $sourceType,
            $sourceUuid,
            $validated['firewallDestinationUuid'],
            $validated['firewallProtocol'],
            $validated['firewallPort'],
        );
        if ($this->getErrorBag()->isEmpty()) {
            $this->reset('firewallSourceUuid', 'firewallDestinationUuid');
            $this->dispatch('close-modal');
        }
    }

    /** @return array{uuid: string, sourceType: string, sourceUuid: string, destinationUuid: string, protocol: string, port: int}|null */
    public function createFirewallRule(string $sourceType, string $sourceUuid, string $destinationUuid, string $protocol, int $port): ?array
    {
        $this->authorize('update', $this->cluster);
        try {
            $result = ManageNodeClusterSettings::make()->addFirewallRule($this->cluster, $sourceType, $sourceUuid, $destinationUuid, $protocol, $port, auth()->user());
        } catch (ValidationException $exception) {
            $this->addErrors($exception, ['source' => 'firewallSourceUuid', 'destination' => 'firewallDestinationUuid', 'source_type' => 'firewallSourceUuid', 'protocol' => 'firewallProtocol', 'port' => 'firewallPort']);

            return null;
        }
        if ($result['created']) {
            $this->dispatchFirewallRulesChanged();
        }
        $this->dispatch('success', $result['created'] ? 'Firewall rule added and reconciliation queued.' : 'The firewall rule already exists.');

        return [
            'uuid' => $result['rule']->uuid,
            'sourceType' => $sourceType,
            'sourceUuid' => $sourceUuid,
            'destinationUuid' => $destinationUuid,
            'protocol' => $result['rule']->protocol,
            'port' => $result['rule']->port,
        ];
    }

    public function removeFirewallRule(string $ruleUuid): void
    {
        $this->authorize('update', $this->cluster);
        $rule = NodeFirewallRule::query()->where('node_cluster_id', $this->cluster->id)->where('uuid', $ruleUuid)->firstOrFail();
        ManageNodeClusterSettings::make()->removeFirewallRule($this->cluster, $rule, auth()->user());
        $this->dispatchFirewallRulesChanged();
        $this->dispatch('success', 'Firewall rule removed and reconciliation queued.');
    }

    public function reconcileNetwork(): void
    {
        $this->authorize('update', $this->cluster);
        $this->cluster->refresh();
        if ($this->cluster->network_status === 'reconciling') {
            $this->dispatch('info', 'Cluster network reconciliation is already running.');

            return;
        }
        if (! $this->cluster->nodes()->exists()) {
            $this->dispatch('error', 'Assign at least one server before network activation.');

            return;
        }

        $this->cluster->update(['network_status' => 'reconciling']);
        ReconcileNodeClusterNetworkJob::dispatch($this->cluster->id, auth()->id());
        $this->dispatch('success', 'Cluster network reconciliation queued.');
    }

    public function repairNetwork(): void
    {
        $this->authorize('update', $this->cluster);
        try {
            RepairNodeClusterNetwork::run($this->cluster);
        } catch (LockTimeoutException|RuntimeException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $this->cluster->update(['network_status' => 'pending']);
        $this->dispatch('success', 'Cluster network state restored over SSH. Reconcile the network to verify current state.');
    }

    /** Upgrades Sentinel on the cluster's servers one at a time, stopping at the first failure. */
    public function upgradeClusterSentinel(): void
    {
        $this->authorize('update', $this->cluster);
        $release = FetchLatestSentinelRelease::run();
        $nodes = UpgradeSentinel::clusterNodesToUpgrade($this->cluster, $release);
        foreach ($nodes as $node) {
            $this->authorize('manageSentinel', $node);
        }
        if ($nodes->isEmpty()) {
            $this->dispatch('info', 'Sentinel is up to date on every server of this cluster.');

            return;
        }
        $key = UpgradeSentinel::clusterUpgradeCacheKey($this->cluster->id);
        if (in_array(data_get(Cache::get($key), 'status'), ['queued', 'running'], true)) {
            $this->dispatch('error', 'A Sentinel upgrade is already running in this cluster.');

            return;
        }

        Cache::put($key, UpgradeSentinel::newClusterRun('queued', $release['version'], $nodes), now()->addDay());
        UpgradeClusterSentinelJob::dispatch($this->cluster->id, auth()->id());
        $this->dispatch('success', 'Sentinel upgrade queued. Servers are upgraded one at a time.');
    }

    public function render(): View
    {
        return view('livewire.node-cluster.show', [
            'nodesNeedAttention' => $this->cluster->hasNodesNeedingAttention(),
            'needsImageStorageChoice' => $this->cluster->needsImageStorageChoice(),
            ...match ($this->section) {
                'general' => $this->generalData(),
                'resources' => $this->resourcesData(),
                'nodes' => $this->nodesData(),
                'firewall' => $this->firewallData(),
                'advanced' => $this->advancedData(),
                default => [],
            },
        ]);
    }

    /** @return array{nodesCount: int, readyNodesCount: int} */
    private function generalData(): array
    {
        return [
            'nodesCount' => $this->cluster->nodes()->count(),
            'readyNodesCount' => $this->cluster->nodes()->where('is_usable', true)->count(),
        ];
    }

    /**
     * Cluster applications with their project, servers, domains, and observed state.
     *
     * @return array{resources: list<array{uuid: string, name: string, href: ?string, project: ?string, environment: ?string, image: ?string, servers: list<string>, domains: list<string>, status: string, statusType: string}>, resourcesTotal: int}
     */
    private function resourcesData(): array
    {
        $workloads = $this->meshWorkloads()
            ->with([
                'environment.project',
                'revisions' => fn ($query) => $query->latest('id'),
                'nodes' => fn ($query) => $query->where('nodes.node_cluster_id', $this->cluster->id)->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();
        $search = mb_strtolower(trim($this->resourceSearch));
        $resources = $workloads
            ->map(function (NodeWorkload $workload): array {
                $states = $workload->nodes->map(fn (Node $node) => DetermineWorkloadState::run($node, $workload));
                $state = $states->first(fn (NodeWorkloadState $state): bool => $state !== NodeWorkloadState::RUNNING)
                    ?? $states->first()
                    ?? NodeWorkloadState::UNKNOWN;
                $project = $workload->environment?->project;

                return [
                    'uuid' => $workload->uuid,
                    'name' => $workload->name,
                    'href' => $project ? route('project.cluster-application.show', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $workload->environment->uuid,
                        'workload_uuid' => $workload->uuid,
                    ]) : null,
                    'project' => $project?->name,
                    'environment' => $workload->environment?->name,
                    'image' => $workload->revisions->first()?->image,
                    'servers' => $workload->nodes->pluck('name')->all(),
                    'domains' => $workload->hasIngressRoutes() ? array_values($workload->domains) : [],
                    'status' => str($state->value)->title()->toString(),
                    'statusType' => $state->badgeType(),
                ];
            })
            ->filter(fn (array $resource): bool => $search === '' || collect([$resource['name'], $resource['project'], $resource['environment'], $resource['image'], ...$resource['servers'], ...$resource['domains']])
                ->contains(fn (?string $value): bool => str_contains(mb_strtolower((string) $value), $search)))
            ->values()
            ->all();

        return ['resources' => $resources, 'resourcesTotal' => $workloads->count()];
    }

    /** @return array<string, mixed> */
    private function nodesData(): array
    {
        $sentinelRelease = FetchLatestSentinelRelease::run();
        $sentinelUpgradeRun = Cache::get(UpgradeSentinel::clusterUpgradeCacheKey($this->cluster->id));
        $operationIds = collect(data_get($sentinelUpgradeRun, 'nodes', []))->pluck('operation_id')->filter()->all();

        return [
            'nodes' => $this->clusterNodes()->get(),
            'sentinelRelease' => $sentinelRelease,
            'sentinelUpgradeNodes' => UpgradeSentinel::clusterNodesToUpgrade($this->cluster, $sentinelRelease),
            'sentinelUpgradeRun' => $sentinelUpgradeRun,
            'sentinelUpgradeOperations' => NodeOperation::query()->whereIn('id', $operationIds)->whereHas('node', fn (Builder $query) => $query->where('node_cluster_id', $this->cluster->id))->get()->keyBy('id'),
            'availableNodes' => auth()->user()->can('update', $this->cluster)
                ? Node::query()->where('team_id', $this->cluster->team_id)->whereNull('node_cluster_id')->orderBy('name')->get()
                : collect(),
        ];
    }

    /** @return array<string, mixed> */
    private function firewallData(): array
    {
        $nodes = $this->clusterNodes()->get();
        $workloads = $this->meshWorkloads()->orderBy('name')->get();
        $firewallRules = NodeFirewallRule::query()
            ->with(['sourceWorkload', 'sourceNode', 'destinationWorkload'])
            ->where('node_cluster_id', $this->cluster->id)
            ->orderBy('id')
            ->get();
        $firewallCanvasNodes = $nodes->map(fn (Node $node): array => [
            'id' => 'node:'.$node->uuid,
            'type' => 'node',
            'uuid' => $node->uuid,
            'name' => $node->name,
            'subtitle' => $node->wireguard_ip ?? 'Server',
            'status' => $node->is_usable ? 'Ready' : 'Not ready',
        ])->concat($workloads->map(fn (NodeWorkload $workload): array => [
            'id' => 'workload:'.$workload->uuid,
            'type' => 'workload',
            'uuid' => $workload->uuid,
            'name' => $workload->name,
            'subtitle' => $workload->internal_dns_name
                ? $workload->internal_dns_name.'.default.coolify.internal'
                : 'Internal DNS pending',
            'status' => str($workload->desired_state->value)->title()->toString(),
        ]))->values();
        $firewallCanvasRules = $this->firewallCanvasRules($firewallRules);

        return compact('nodes', 'workloads', 'firewallRules', 'firewallCanvasNodes', 'firewallCanvasRules');
    }

    /**
     * @param  Collection<int, NodeFirewallRule>  $firewallRules
     * @return list<array{uuid: string, sourceType: string, sourceUuid: string|null, destinationUuid: string, protocol: string, port: int}>
     */
    private function firewallCanvasRules(Collection $firewallRules): array
    {
        return $firewallRules->map(fn (NodeFirewallRule $rule): array => [
            'uuid' => $rule->uuid,
            'sourceType' => $rule->sourceNode !== null ? 'node' : 'workload',
            'sourceUuid' => $rule->sourceNode?->uuid ?? $rule->sourceWorkload?->uuid,
            'destinationUuid' => $rule->destinationWorkload->uuid,
            'protocol' => $rule->protocol,
            'port' => $rule->port,
        ])->values()->all();
    }

    /**
     * Sends the current rules to the traffic map, which Livewire does not re-render.
     */
    private function dispatchFirewallRulesChanged(): void
    {
        $this->dispatch('firewall-rules-changed', rules: $this->firewallCanvasRules(
            NodeFirewallRule::query()
                ->with(['sourceWorkload', 'sourceNode', 'destinationWorkload'])
                ->where('node_cluster_id', $this->cluster->id)
                ->orderBy('id')
                ->get(),
        ));
    }

    /** @return array<string, mixed> */
    private function advancedData(): array
    {
        return [
            'registryCredentialOptions' => RegistryCredential::query()
                ->where('team_id', $this->cluster->team_id)
                ->orderBy('name')
                ->get()
                ->map(fn (RegistryCredential $credential): array => [
                    'value' => $credential->uuid,
                    'label' => $credential->name,
                    'description' => $credential->registry.' · '.$credential->username,
                ])
                ->prepend(['value' => '', 'label' => 'None', 'description' => 'A registry without a login'])
                ->values()
                ->all(),
            'operations' => NodeOperation::query()
                ->with('node')
                ->whereHas('node', fn ($query) => $query->where('team_id', $this->cluster->team_id)->where('node_cluster_id', $this->cluster->id))
                ->userFacing()
                ->latest('id')
                ->limit(10)
                ->get(),
        ];
    }

    private function clusterNodes(): Builder
    {
        return Node::query()->where('team_id', $this->cluster->team_id)->where('node_cluster_id', $this->cluster->id)->orderBy('name');
    }

    private function meshWorkloads(): Builder
    {
        return ManageNodeClusterSettings::meshWorkloads($this->cluster);
    }

    /**
     * Shows the errors of a settings action on the fields of the form.
     *
     * @param  array<string, string>  $fields  The action field names to the property names.
     */
    private function addErrors(ValidationException $exception, array $fields): void
    {
        foreach ($exception->errors() as $key => $messages) {
            $this->addError($fields[$key] ?? $key, $messages[0]);
        }
    }

    private function fillFromCluster(): void
    {
        $this->name = $this->cluster->name;
        $this->description = $this->cluster->description ?? '';
        $this->cidr = $this->cluster->cidr;
        $this->wireguardInterface = $this->cluster->wireguard_interface;
        $this->wireguardPort = $this->cluster->wireguard_port;
        $this->cpuPressureThreshold = $this->cluster->cpu_pressure_threshold;
        $this->memoryPressureThreshold = $this->cluster->memory_pressure_threshold;
        $this->diskPressureThreshold = $this->cluster->disk_pressure_threshold;
        $this->resourceStaleAfterMinutes = $this->cluster->resource_stale_after_minutes;
        $this->imageStorage = (string) $this->cluster->image_storage?->value;
        $this->imageRepository = (string) $this->cluster->image_repository;
        $this->imageRegistryCredentialUuid = (string) $this->cluster->imageRegistryCredential?->uuid;
    }
}
