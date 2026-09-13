<?php

namespace App\Livewire\Node;

use App\Actions\Node\FetchNodeDiscoveryEndpoints;
use App\Models\Node;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class InternalDns extends Component
{
    use AuthorizesRequests;

    public Node $node;

    /** @var array<int, array<string, mixed>> */
    public array $endpoints = [];

    /** @var array<string, string> */
    public array $nodeNamesByAddress = [];

    public ?string $loadError = null;

    public function mount(string $node_uuid): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->node = Node::query()
            ->where('uuid', $node_uuid)
            ->where('team_id', currentTeam()->id)
            ->firstOrFail();
        $this->authorize('view', $this->node);
        $this->node->load('cluster.nodes');
        $this->nodeNamesByAddress = $this->node->cluster?->nodes
            ->where('team_id', $this->node->team_id)
            ->pluck('name', 'wireguard_ip')
            ->all() ?? [];
        $this->loadEndpoints();
    }

    public function refreshEndpoints(): void
    {
        $this->authorize('view', $this->node);
        $this->loadEndpoints();
        if ($this->loadError !== null) {
            $this->dispatch('error', $this->loadError);

            return;
        }
        $this->dispatch('success', 'Internal DNS records refreshed.');
    }

    public function render(): View
    {
        return view('livewire.node.internal-dns');
    }

    private function loadEndpoints(): void
    {
        try {
            $this->endpoints = FetchNodeDiscoveryEndpoints::run($this->node);
            $this->loadError = null;
        } catch (\Throwable) {
            $this->endpoints = [];
            $this->loadError = 'Could not read internal DNS records from this Node.';
        }
    }
}
