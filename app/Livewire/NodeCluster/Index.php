<?php

namespace App\Livewire\NodeCluster;

use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\FetchLatestSentinelRelease;
use App\Actions\Node\UpgradeSentinel;
use App\Jobs\UpgradeAllNodeSentinelsJob;
use App\Models\Node;
use App\Models\NodeCluster;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
        $this->dispatch('close-modal');
        $this->dispatch('success', 'Cluster created.');
    }

    /** Upgrades Sentinel on every usable Node of the current team, one at a time, stopping at the first failure. */
    public function upgradeAllSentinels(): void
    {
        $nodes = $this->nodesNeedingSentinelUpgrade(FetchLatestSentinelRelease::run());
        foreach ($nodes as $node) {
            $this->authorize('manageSentinel', $node);
        }
        if ($nodes->isEmpty()) {
            $this->dispatch('info', 'Sentinel is up to date on every Node.');

            return;
        }
        $teamId = currentTeam()->id;
        if (in_array(data_get(Cache::get(UpgradeSentinel::upgradeAllCacheKey($teamId)), 'status'), ['queued', 'running'], true)) {
            $this->dispatch('error', 'A Sentinel upgrade is already running for this team.');

            return;
        }

        Cache::put(UpgradeSentinel::upgradeAllCacheKey($teamId), ['status' => 'queued', 'upgraded' => []], now()->addDay());
        UpgradeAllNodeSentinelsJob::dispatch($teamId, auth()->id());
        $this->dispatch('success', 'Sentinel upgrade queued. Nodes are upgraded one at a time.');
    }

    public function render(): View
    {
        $clusters = NodeCluster::query()->where('team_id', currentTeam()->id)->withCount('nodes')->orderBy('name')->get();
        $nodes = Node::query()->with('cluster:id,name,uuid')->where('team_id', currentTeam()->id)->orderBy('name')->get();
        $sentinelRelease = FetchLatestSentinelRelease::run();
        $sentinelUpgradeNodes = $this->nodesNeedingSentinelUpgrade($sentinelRelease);
        $sentinelUpgradeSummary = Cache::get(UpgradeSentinel::upgradeAllCacheKey(currentTeam()->id));

        return view('livewire.node-cluster.index', compact('clusters', 'nodes', 'sentinelRelease', 'sentinelUpgradeNodes', 'sentinelUpgradeSummary'));
    }

    /**
     * @param  array{version: string}|null  $release
     * @return Collection<int, Node>
     */
    private function nodesNeedingSentinelUpgrade(?array $release): Collection
    {
        if ($release === null) {
            return collect();
        }

        return Node::query()
            ->where('team_id', currentTeam()->id)
            ->where('is_usable', true)
            ->get()
            ->filter(fn (Node $node): bool => $node->needsSentinelUpgrade($release))
            ->values()
            ->toBase();
    }
}
