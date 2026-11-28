<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use App\Models\NodePublishedPort;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/** Stops publishing a port of a cluster workload; the next network revision closes it on every Node. */
class RemoveNodePublishedPort
{
    use AsAction;

    public function handle(NodePublishedPort $port, User $user): void
    {
        Gate::forUser($user)->authorize('update', $port->workload);
        $cluster = NodeCluster::query()->findOrFail($port->node_cluster_id);
        $port->delete();
        QueueNodeClusterNetworkRevision::run($cluster, $user);
        SyncNodeWorkloadSet::queueForCluster($cluster);
    }
}
