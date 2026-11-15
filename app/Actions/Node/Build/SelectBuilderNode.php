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
 * builder role and a running BuildKit, with the fewest active builds. A
 * private repository also needs a Sentinel that accepts Git credentials.
 * BuildKit builds for the CPU architecture of its own Node, so the builder
 * has the architecture of the Nodes that run the workload (decision 0020).
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
        $capabilities = $workload->buildCapabilities();
        $architecture = self::targetArchitecture($workload);
        $candidates = Node::query()
            ->whereIn('node_cluster_id', $clusterIds)
            ->withCount(['builds as active_builds_count' => fn ($query) => $query->active()->whereNotNull('started_at')])
            ->orderBy('active_builds_count')
            ->orderBy('id')
            ->get()
            ->filter(fn (Node $node): bool => $node->canBuild()
                && $node->canReceiveNetworkCommands()
                && collect($capabilities)->every(fn (string $capability): bool => $node->supportsCapability($capability) === true)
                && data_get($node->metadata, 'builder_running') === true);
        $fitting = $candidates->filter(fn (Node $node): bool => $architecture === null || in_array($node->cpuArchitecture(), [$architecture, null], true));
        if ($candidates->isNotEmpty() && $fitting->isEmpty()) {
            throw new RuntimeException("No builder server in this cluster has the {$architecture} CPU architecture of the servers that run this application. Turn on the builder role on a server with this architecture. Coolify does not build for another architecture.");
        }
        $candidates = $fitting;

        // One build at a time on each builder Node; the job waits for a free one.
        $node = $candidates->first(fn (Node $node): bool => $node->active_builds_count === 0);
        if ($node === null) {
            $features = array_diff($capabilities, [NodeRegistry::BUILD_CAPABILITY]);
            throw new RuntimeException($candidates->isEmpty()
                ? ($features !== []
                    ? 'No server in this cluster can build this application. Turn on the builder role on a server, and upgrade Sentinel to a version that supports '.implode(', ', $features).'.'
                    : 'No server in this cluster can build images. Turn on the builder role on a server, and upgrade Sentinel if needed.')
                : NodeBuild::BUILDERS_BUSY);
        }

        return $node;
    }

    /** The CPU architecture of the Nodes that run the workload, or null when it is unknown. */
    private static function targetArchitecture(NodeWorkload $workload): ?string
    {
        $architectures = $workload->nodes()->get()->map(fn (Node $node): ?string => $node->cpuArchitecture())->filter()->unique()->values();
        if ($architectures->count() > 1) {
            throw new RuntimeException('The application runs on servers with different CPU architectures ('.$architectures->implode(', ').'). Move it to servers of one architecture first.');
        }

        return $architectures->first();
    }
}
