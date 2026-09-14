<?php

namespace App\Livewire\NodeCluster;

use App\Actions\Node\CreateNodeCluster;
use App\Models\Node;
use App\Models\NodeCluster;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    #[Validate('nullable|string|max:64')]
    public string $cidr = '';

    public function mount(): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->authorize('viewAny', NodeCluster::class);
    }

    public function createCluster(): void
    {
        $this->authorize('create', NodeCluster::class);
        $this->validate();
        $team = auth()->user()->currentTeam();
        CreateNodeCluster::run($team, auth()->user(), $this->name, blank($this->description) ? null : $this->description, blank($this->cidr) ? null : $this->cidr);
        $this->reset('name', 'description', 'cidr');
        $this->dispatch('closeModal');
        $this->dispatch('success', 'Cluster created.');
    }

    public function render(): View
    {
        $clusters = NodeCluster::query()->whereIn('team_id', auth()->user()->teams()->select('teams.id'))->withCount('nodes')->orderBy('name')->get();
        $nodes = Node::query()->where('team_id', currentTeam()->id)->orderBy('name')->get();

        return view('livewire.node-cluster.index', compact('clusters', 'nodes'));
    }
}
