<?php

namespace App\Livewire\NodeCluster;

use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\FetchLatestSentinelRelease;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Rules\PrivateIpv4Cidr;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Cluster groups on the Servers page: one group per cluster with its servers (Node models),
 * servers without a cluster, and cluster creation. Sentinel upgrades run per cluster on its Servers page.
 */
class Index extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $description = '';

    public string $cidr = '';

    public function mount(): void
    {
        abort_unless(nodesEnabled(), 404);
        $this->authorize('viewAny', NodeCluster::class);
    }

    public function createCluster(): void
    {
        $this->authorize('create', NodeCluster::class);
        $team = auth()->user()->currentTeam();
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('node_clusters')->where('team_id', $team->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'cidr' => ['nullable', 'string', 'max:64', new PrivateIpv4Cidr],
        ]);
        try {
            CreateNodeCluster::run($team, auth()->user(), $this->name, blank($this->description) ? null : $this->description, blank($this->cidr) ? null : $this->cidr);
        } catch (DomainException $exception) {
            $this->addError('cidr', $exception->getMessage());

            return;
        }
        $this->reset('name', 'description', 'cidr');
        $this->dispatch('closeModal');
        $this->dispatch('close-modal');
        $this->dispatch('success', 'Cluster created.');
    }

    public function render(): View
    {
        $teamId = currentTeam()->id;
        $clusters = NodeCluster::query()
            ->where('team_id', $teamId)
            ->with(['nodes' => fn ($query) => $query->where('team_id', $teamId)->orderBy('name')])
            ->orderBy('name')
            ->get();
        $unassignedNodes = Node::query()->where('team_id', $teamId)->whereNull('node_cluster_id')->orderBy('name')->get();
        $sentinelRelease = FetchLatestSentinelRelease::run();

        return view('livewire.node-cluster.index', compact('clusters', 'unassignedNodes', 'sentinelRelease'));
    }
}
