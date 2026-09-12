<?php

namespace App\Actions\Node;

use App\Enums\NodeWorkloadState;
use App\Models\Node;
use App\Models\NodeWorkload;
use Illuminate\Support\Carbon;
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

        $currentRevision = $workload->revisions()->latest('id')->first();
        if ($currentRevision === null) {
            return NodeWorkloadState::MISSING;
        }

        $containers = $workload->containers()
            ->where('node_id', $node->id)
            ->where('is_managed', true)
            ->get(['node_workload_revision_id', 'state']);
        $currentContainers = $containers->where('node_workload_revision_id', $currentRevision->id);

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
