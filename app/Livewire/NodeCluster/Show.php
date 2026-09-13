<?php

namespace App\Livewire\NodeCluster;

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\RepairNodeClusterNetwork;
use App\Actions\Node\UpdateNodeCluster;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Rules\PrivateIpv4Cidr;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    public string $nodeUuid = '';

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
        $operations = NodeOperation::query()
            ->with('node')
            ->whereHas('node', fn ($query) => $query->where('team_id', currentTeam()->id)->where('node_cluster_id', $this->cluster->id))
            ->latest('id')
            ->limit(30)
            ->get();

        return view('livewire.node-cluster.show', compact('nodes', 'availableNodes', 'operations'));
    }

    private function fillFromCluster(): void
    {
        $this->name = $this->cluster->name;
        $this->description = $this->cluster->description ?? '';
        $this->cidr = $this->cluster->cidr;
        $this->wireguardInterface = $this->cluster->wireguard_interface;
        $this->wireguardPort = $this->cluster->wireguard_port;
    }
}
