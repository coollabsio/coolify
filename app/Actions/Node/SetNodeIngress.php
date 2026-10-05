<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Turns the HTTP ingress proxy of a cluster Node on or off. The Node's Sentinel must report the
 * ingress capability before Coolify relies on it.
 */
class SetNodeIngress
{
    use AsAction;

    public function handle(NodeCluster $cluster, Node $node, bool $enabled, User $user): Node
    {
        Gate::forUser($user)->authorize('update', $cluster);
        if ($node->team_id !== $cluster->team_id || $node->node_cluster_id !== $cluster->id) {
            throw new DomainException('The server does not belong to this cluster.');
        }
        if ($enabled && ! $node->supportsIngress()) {
            throw new DomainException('Upgrade Sentinel on this server to use ingress.');
        }
        if ($node->is_ingress === $enabled) {
            return $node;
        }

        $node->update(['is_ingress' => $enabled]);
        QueueNodeClusterNetworkRevision::run($cluster, $user);

        return $node->refresh();
    }
}
