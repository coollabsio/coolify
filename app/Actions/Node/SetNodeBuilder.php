<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Turns the builder role of a cluster Node on or off. The next network revision starts or
 * removes BuildKit and its build cache on that Node.
 */
class SetNodeBuilder
{
    use AsAction;

    public function handle(NodeCluster $cluster, Node $node, bool $enabled, User $user): Node
    {
        Gate::forUser($user)->authorize('update', $cluster);
        if ($node->team_id !== $cluster->team_id || $node->node_cluster_id !== $cluster->id) {
            throw new DomainException('The server does not belong to this cluster.');
        }
        if ($node->is_builder === $enabled) {
            return $node;
        }

        $node->update(['is_builder' => $enabled]);
        QueueNodeClusterNetworkRevision::run($cluster, $user);

        return $node->refresh();
    }
}
