<?php

namespace App\Jobs;

use App\Actions\Node\InspectNodeClusterDrift;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Models\Node;
use App\Models\NodeCluster;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Repairs cluster networks Node by Node: a reachable Node that is behind, drifted, or failed
 * and passed its retry backoff gets its own reconciliation. Offline Nodes are skipped.
 */
class InspectNodeClusterNetworksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        Node::query()
            ->whereNull('node_cluster_id')
            ->whereNotNull('network_pending_leave')
            ->where('is_usable', true)
            ->chunkById(100, function ($nodes): void {
                foreach ($nodes as $node) {
                    if ($node->canReceiveNetworkCommands()) {
                        CompleteNodeClusterLeaveJob::dispatch($node->id);
                    }
                }
            });

        NodeCluster::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('network_status', ['active', 'degraded', 'error'])
                ->orWhere(fn (Builder $query) => $query->where('network_status', 'pending')->whereNotNull('last_reconciled_at')))
            ->chunkById(50, function ($clusters): void {
                foreach ($clusters as $cluster) {
                    $this->repair($cluster);
                }
            });
    }

    private function repair(NodeCluster $cluster): void
    {
        $operator = $cluster->networkOperator();
        if ($operator === null) {
            return;
        }
        $lock = $cluster->networkLock();
        if (! $lock->get()) {
            return;
        }

        try {
            $cluster->refresh();
            if ($cluster->network_status === 'reconciling') {
                return;
            }

            $drifted = InspectNodeClusterDrift::run($cluster);
            if ($drifted !== []) {
                // Re-apply the same revision to the drifted Nodes only.
                Node::query()->whereKey($drifted)->update([
                    'network_status' => 'pending',
                    'network_error' => 'The host network drifted from the desired revision.',
                ]);
            }

            $due = $cluster->nodes()
                ->orderBy('id')
                ->get()
                ->filter(fn (Node $node): bool => $node->canReceiveNetworkCommands() && ReconcileNodeClusterNetwork::isDue($cluster, $node))
                ->pluck('id')
                ->values()
                ->all();

            $status = $cluster->deriveNetworkStatus();
            if ($status !== $cluster->network_status) {
                $cluster->update(['network_status' => $status]);
            }
        } finally {
            $lock->release();
        }

        if ($due !== []) {
            ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $operator->id, $due, onlyDueNodes: true);
        }
    }
}
