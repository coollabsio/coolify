<?php

namespace App\Actions\Node;

use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Publishes a change of the cluster network intent, such as ingress routes: it bumps the desired
 * revision and queues one Node-scoped reconciliation for the reachable member Nodes. Offline Nodes
 * stay behind and converge when they reconnect.
 */
class QueueNodeClusterNetworkRevision
{
    use AsAction;

    public function handle(NodeCluster $cluster, ?User $user = null): bool
    {
        $cluster->increment('desired_revision');
        $cluster->refresh();
        if (! $cluster->convergesAutomatically()) {
            return false;
        }

        $operator = $user !== null && $user->can('update', $cluster) ? $user : $cluster->networkOperator();
        if ($operator === null) {
            return false;
        }
        $nodeIds = $cluster->nodes()
            ->orderBy('id')
            ->get()
            ->filter(fn (Node $node): bool => $node->canReceiveNetworkCommands())
            ->pluck('id')
            ->values()
            ->all();
        if ($nodeIds === []) {
            return false;
        }
        // The scheduled repair skips the cluster for a short time, so it does not queue a second run for the same Nodes.
        Cache::put(self::queuedCacheKey($cluster), true, 120);
        ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $operator->id, $nodeIds)->afterCommit();

        return true;
    }

    public static function queuedCacheKey(NodeCluster $cluster): string
    {
        return "node-cluster-network-revision-queued:{$cluster->id}";
    }
}
