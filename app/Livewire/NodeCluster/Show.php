<?php

namespace App\Livewire\NodeCluster;

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\RepairNodeClusterNetwork;
use App\Actions\Node\UpdateNodeCluster;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeIngressRule;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Rules\PrivateIpv4Cidr;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public NodeCluster $cluster;

    public string $name = '';

    public string $description = '';

    public string $cidr = '';

    public string $wireguardInterface = 'coolify0';

    public int $wireguardPort = 51820;

    public int $cpuPressureThreshold = 95;

    public int $memoryPressureThreshold = 90;

    public int $diskPressureThreshold = 90;

    public int $resourceStaleAfterMinutes = 5;

    public string $nodeUuid = '';

    public string $firewallSourceUuid = '';

    public string $firewallDestinationUuid = '';

    public string $firewallProtocol = 'tcp';

    public int $firewallPort = 80;

    public string $ingressDestinationUuid = '';

    public string $ingressProtocol = 'tcp';

    public int $ingressPort = 80;

    public function mount(string $cluster_uuid): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->cluster = NodeCluster::query()->where('team_id', currentTeam()->id)->where('uuid', $cluster_uuid)->firstOrFail();
        $this->authorize('view', $this->cluster);
        $this->fillFromCluster();
    }

    public function saveSettings(): void
    {
        $this->authorize('update', $this->cluster);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('node_clusters')->where('team_id', $this->cluster->team_id)->ignore($this->cluster->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'cidr' => ['required', new PrivateIpv4Cidr],
            'wireguardInterface' => ['required', 'regex:/^[a-zA-Z0-9_.-]{1,15}$/'],
            'wireguardPort' => ['required', 'integer', 'between:1,65535'],
        ]);
        if ($this->cluster->hasActivatedNetwork() && $validated['cidr'] !== $this->cluster->cidr) {
            $this->addError('cidr', 'An active cluster CIDR cannot be changed.');

            return;
        }
        try {
            $this->cluster = UpdateNodeCluster::run($this->cluster, $validated['name'], blank($validated['description']) ? null : $validated['description'], $validated['cidr'], $validated['wireguardInterface'], $validated['wireguardPort']);
        } catch (DomainException $exception) {
            $this->addError('cidr', $exception->getMessage());

            return;
        }
        $this->fillFromCluster();
        $this->dispatch('success', 'Cluster settings saved.');
    }

    public function assignNode(): void
    {
        $this->authorize('update', $this->cluster);
        $this->validate(['nodeUuid' => ['required', 'string']]);
        $node = Node::query()->where('team_id', currentTeam()->id)->whereNull('node_cluster_id')->where('uuid', $this->nodeUuid)->firstOrFail();
        AssignNodeToCluster::run($this->cluster, $node);
        $this->cluster->refresh();
        $this->reset('nodeUuid');
        $this->dispatch('success', 'Node assigned to the cluster.');
    }

    public function savePressurePolicy(): void
    {
        $this->authorize('update', $this->cluster);
        $validated = $this->validate([
            'cpuPressureThreshold' => ['required', 'integer', 'between:1,100'],
            'memoryPressureThreshold' => ['required', 'integer', 'between:1,100'],
            'diskPressureThreshold' => ['required', 'integer', 'between:1,100'],
            'resourceStaleAfterMinutes' => ['required', 'integer', 'between:1,60'],
        ]);
        $this->cluster->update([
            'cpu_pressure_threshold' => $validated['cpuPressureThreshold'],
            'memory_pressure_threshold' => $validated['memoryPressureThreshold'],
            'disk_pressure_threshold' => $validated['diskPressureThreshold'],
            'resource_stale_after_minutes' => $validated['resourceStaleAfterMinutes'],
        ]);
        $this->cluster->refresh();
        $this->fillFromCluster();
        $this->dispatch('success', 'Deployment pressure policy saved.');
    }

    public function removeNode(string $nodeUuid): void
    {
        $this->authorize('update', $this->cluster);
        $node = Node::query()->where('team_id', currentTeam()->id)->where('node_cluster_id', $this->cluster->id)->where('uuid', $nodeUuid)->firstOrFail();
        RemoveNodeFromCluster::run($this->cluster, $node);
        $this->cluster->refresh();
        $this->dispatch('success', 'Node removed from the cluster.');
    }

    public function deleteCluster(): void
    {
        $this->authorize('delete', $this->cluster);
        $this->cluster->delete();
        $this->redirectRoute('node-cluster.index', navigate: true);
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
        [$sourceType, $sourceUuid] = array_pad(explode(':', $validated['firewallSourceUuid'], 2), 2, null);
        $sourceWorkload = $sourceType === 'workload' ? $this->meshWorkloads()->where('uuid', $sourceUuid)->first() : null;
        $sourceNode = $sourceType === 'node'
            ? Node::query()->where('team_id', $this->cluster->team_id)->where('node_cluster_id', $this->cluster->id)->where('uuid', $sourceUuid)->first()
            : null;
        $destination = $this->meshWorkloads()->where('uuid', $validated['firewallDestinationUuid'])->first();
        if ($sourceWorkload === null && $sourceNode === null) {
            $this->addError('firewallSourceUuid', 'Select a workload or Node from this mesh.');
        }
        if ($destination === null) {
            $this->addError('firewallDestinationUuid', 'Select a workload from this mesh.');
        }
        if ($sourceWorkload?->id === $destination?->id) {
            $this->addError('firewallDestinationUuid', 'The source and destination workloads must be different.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $created = DB::transaction(function () use ($validated, $sourceWorkload, $sourceNode, $destination): bool {
            $rule = NodeFirewallRule::query()->firstOrCreate([
                'node_cluster_id' => $this->cluster->id,
                'source_workload_id' => $sourceWorkload?->id,
                'source_node_id' => $sourceNode?->id,
                'destination_workload_id' => $destination->id,
                'protocol' => $validated['firewallProtocol'],
                'port' => $validated['firewallPort'],
            ]);
            if ($rule->wasRecentlyCreated) {
                $this->cluster->increment('desired_revision');
            }

            return $rule->wasRecentlyCreated;
        });
        if ($created) {
            $this->queueNetworkReconciliation();
        }
        $this->reset('firewallSourceUuid', 'firewallDestinationUuid');
        $this->dispatch('success', $created ? 'Firewall rule added and reconciliation queued.' : 'The firewall rule already exists.');
    }

    public function removeFirewallRule(string $ruleUuid): void
    {
        $this->authorize('update', $this->cluster);
        DB::transaction(function () use ($ruleUuid): void {
            NodeFirewallRule::query()
                ->where('node_cluster_id', $this->cluster->id)
                ->where('uuid', $ruleUuid)
                ->firstOrFail()
                ->delete();
            $this->cluster->increment('desired_revision');
        });
        $this->queueNetworkReconciliation();
        $this->dispatch('success', 'Firewall rule removed and reconciliation queued.');
    }

    public function addIngressRule(): void
    {
        $this->authorize('update', $this->cluster);
        $validated = $this->validate([
            'ingressDestinationUuid' => ['required', 'string'],
            'ingressProtocol' => ['required', Rule::in(['tcp', 'udp'])],
            'ingressPort' => ['required', 'integer', 'between:1,65535'],
        ]);
        $destination = $this->meshWorkloads()->where('uuid', $validated['ingressDestinationUuid'])->first();
        if ($destination === null) {
            $this->addError('ingressDestinationUuid', 'Select a workload from this mesh.');

            return;
        }

        $created = DB::transaction(function () use ($validated, $destination): bool {
            $rule = NodeIngressRule::query()->firstOrCreate([
                'node_cluster_id' => $this->cluster->id,
                'destination_workload_id' => $destination->id,
                'protocol' => $validated['ingressProtocol'],
                'port' => $validated['ingressPort'],
            ]);
            if ($rule->wasRecentlyCreated) {
                $this->cluster->increment('desired_revision');
            }

            return $rule->wasRecentlyCreated;
        });
        if ($created) {
            $this->queueNetworkReconciliation();
        }
        $this->reset('ingressDestinationUuid');
        $this->dispatch('success', $created ? 'Ingress rule added and reconciliation queued.' : 'The ingress rule already exists.');
    }

    public function removeIngressRule(string $ruleUuid): void
    {
        $this->authorize('update', $this->cluster);
        DB::transaction(function () use ($ruleUuid): void {
            NodeIngressRule::query()
                ->where('node_cluster_id', $this->cluster->id)
                ->where('uuid', $ruleUuid)
                ->firstOrFail()
                ->delete();
            $this->cluster->increment('desired_revision');
        });
        $this->queueNetworkReconciliation();
        $this->dispatch('success', 'Ingress rule removed and reconciliation queued.');
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
            $this->dispatch('error', 'Assign at least one Node before network activation.');

            return;
        }

        $this->cluster->update(['network_status' => 'reconciling']);
        ReconcileNodeClusterNetworkJob::dispatch($this->cluster->id, auth()->id());
        $this->dispatch('success', 'Cluster network reconciliation queued.');
    }

    public function repairNetwork(): void
    {
        $this->authorize('update', $this->cluster);
        RepairNodeClusterNetwork::run($this->cluster);
        $this->cluster->update(['network_status' => 'pending']);
        $this->dispatch('success', 'Cluster network state restored over SSH. Reconcile the network to verify current state.');
    }

    public function render(): View
    {
        $nodes = Node::query()->where('team_id', currentTeam()->id)->where('node_cluster_id', $this->cluster->id)->orderBy('name')->get();
        $availableNodes = Node::query()->where('team_id', currentTeam()->id)->whereNull('node_cluster_id')->orderBy('name')->get();
        $workloads = $this->meshWorkloads()->orderBy('name')->get();
        $firewallRules = NodeFirewallRule::query()
            ->with(['sourceWorkload', 'sourceNode', 'destinationWorkload'])
            ->where('node_cluster_id', $this->cluster->id)
            ->orderBy('id')
            ->get();
        $ingressRules = NodeIngressRule::query()
            ->with('destinationWorkload')
            ->where('node_cluster_id', $this->cluster->id)
            ->orderBy('id')
            ->get();
        $operations = NodeOperation::query()
            ->with('node')
            ->whereHas('node', fn ($query) => $query->where('team_id', currentTeam()->id)->where('node_cluster_id', $this->cluster->id))
            ->latest('id')
            ->limit(30)
            ->get();

        return view('livewire.node-cluster.show', compact('nodes', 'availableNodes', 'workloads', 'firewallRules', 'ingressRules', 'operations'));
    }

    private function meshWorkloads(): Builder
    {
        return NodeWorkload::query()
            ->where('team_id', $this->cluster->team_id)
            ->whereHas('nodes', fn ($query) => $query->where('nodes.node_cluster_id', $this->cluster->id));
    }

    private function queueNetworkReconciliation(): void
    {
        $this->cluster->refresh()->update(['network_status' => 'reconciling']);
        ReconcileNodeClusterNetworkJob::dispatch($this->cluster->id, auth()->id());
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
    }
}
