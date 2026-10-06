<?php

namespace App\Actions\Node;

use App\Enums\NodeWorkloadState;
use App\Models\Node;
use App\Models\NodeContainer;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class DetermineWorkloadState
{
    use AsAction;

    public function handle(Node $node, NodeWorkload $workload): NodeWorkloadState
    {
        if ($node->team_id !== $workload->team_id || ! $node->workloads()->whereKey($workload->id)->exists()) {
            throw new RuntimeException('The workload is not assigned to this Node.');
        }

        return $this->fromInventory(
            $node,
            fn (): ?NodeWorkloadRevision => $workload->revisions()->latest('id')->first(),
            fn (): Collection => $workload->containers()
                ->where('node_id', $node->id)
                ->where('is_managed', true)
                ->get(['node_workload_revision_id', 'state']),
        );
    }

    /**
     * Derives the state from data that the caller already loaded, so lists can
     * eager load revisions and containers instead of querying per workload.
     * The loaders run only when the Node inventory is fresh.
     *
     * @param  callable(): ?NodeWorkloadRevision  $currentRevision  the latest revision of the workload
     * @param  callable(): Collection<int, NodeContainer>  $managedContainers  managed containers of the workload on this Node
     */
    public function fromInventory(Node $node, callable $currentRevision, callable $managedContainers): NodeWorkloadState
    {
        $observedAt = data_get($node->metadata, 'container_inventory_observed_at');
        if (! is_string($observedAt)) {
            return NodeWorkloadState::UNKNOWN;
        }
        try {
            if (Carbon::parse($observedAt)->isBefore(now()->subMinutes(3))) {
                return NodeWorkloadState::STALE;
            }
        } catch (\Throwable) {
            return NodeWorkloadState::UNKNOWN;
        }

        $revision = $currentRevision();
        if ($revision === null) {
            return NodeWorkloadState::MISSING;
        }

        $containers = $managedContainers();
        $currentContainers = $containers->where('node_workload_revision_id', $revision->id);

        if ($currentContainers->contains('state', 'running')) {
            return NodeWorkloadState::RUNNING;
        }
        if ($currentContainers->isNotEmpty()) {
            return NodeWorkloadState::STOPPED;
        }
        if ($containers->isNotEmpty()) {
            return NodeWorkloadState::OUTDATED;
        }

        return NodeWorkloadState::MISSING;
    }
}
