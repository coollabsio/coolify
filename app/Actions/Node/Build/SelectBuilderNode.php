<?php

namespace App\Actions\Node\Build;

use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeRegistry;
use App\Models\NodeWorkload;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Picks the builder Node of a build: a Node of the workload's cluster with the
 * builder role and a running BuildKit, with the fewest active builds.
 */
class SelectBuilderNode
{
    use AsAction;

    public function handle(NodeWorkload $workload): Node
    {
        $clusterIds = $workload->clusterIds();
        if ($clusterIds === []) {
            throw new RuntimeException('The application is not placed on a cluster server.');
        }
        $candidates = Node::query()
            ->whereIn('node_cluster_id', $clusterIds)
            ->withCount(['builds as active_builds_count' => fn ($query) => $query->active()->whereNotNull('started_at')])
            ->orderBy('active_builds_count')
            ->orderBy('id')
            ->get()
            ->filter(fn (Node $node): bool => $node->canBuild()
                && $node->canReceiveNetworkCommands()
                && $node->supportsCapability(NodeRegistry::BUILD_CAPABILITY) === true
                && data_get($node->metadata, 'builder_running') === true);

        // One build at a time on each builder Node; the job waits for a free one.
        $node = $candidates->first(fn (Node $node): bool => $node->active_builds_count === 0);
        if ($node === null) {
            throw new RuntimeException($candidates->isEmpty()
                ? 'No server in this cluster can build images. Turn on the builder role on a server, and upgrade Sentinel if needed.'
                : NodeBuild::BUILDERS_BUSY);
        }

        return $node;
    }
}
