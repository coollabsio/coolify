<?php

namespace App\Actions\Node;

use App\Jobs\CompleteNodeClusterLeaveJob;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Queues the network work a reconnected Node still needs: the leave of a cluster it was
 * removed from while offline, or a Node-scoped reconciliation to the desired revision.
 */
class QueueNodeNetworkConvergence
{
    use AsAction;

    public function handle(Node $node): bool
    {
        if (! $node->canReceiveNetworkCommands()) {
            return false;
        }

        $cluster = $node->cluster;
        if ($cluster === null) {
            if (! is_array($node->network_pending_leave)) {
                return false;
            }
            CompleteNodeClusterLeaveJob::dispatch($node->id);

            return true;
        }

        if (! $cluster->convergesAutomatically()
            || ($cluster->nodeNetworkState($node) === 'converged' && ! is_array($node->network_pending_leave))) {
            return false;
        }
        $operator = $cluster->networkOperator();
        if ($operator === null) {
            return false;
        }
        ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $operator->id, [$node->id]);

        return true;
    }
}
