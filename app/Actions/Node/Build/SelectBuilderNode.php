<?php

namespace App\Actions\Node\Build;

use App\Exceptions\NodePlacementDeniedException;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Picks the builder Node of a build: a Node of the workload's cluster with the
 * builder role, a running BuildKit, and a free build slot, the least busy first. A
 * private repository also needs a Sentinel that accepts Git credentials.
 * BuildKit builds for the CPU architecture of its own Node, so the builder
 * has the architecture of the Nodes that run the workload (decision 0020).
 *
 * A build that a user started runs only on a builder Node where that user may place
 * resources (NodePolicy::placeResource), because the build runs the code of the user there. A
 * build without a user (webhook, reconciler) can use every builder Node.
 */
class SelectBuilderNode
{
    use AsAction;

    public const NOT_ALLOWED = 'You cannot place resources on a builder server of this cluster. Ask an admin for access to a builder server, or to deploy this application.';

    /**
     * The builder Node with a free build slot for a build of the workload. StartQueuedNodeBuilds
     * calls it under a lock on the Nodes, so two builds never take the same slot. It throws
     * NodeBuild::BUILDERS_BUSY while every fitting builder is full; the build then stays in the queue.
     */
    public function handle(NodeWorkload $workload, ?User $requestedBy = null): Node
    {
        $clusterIds = $workload->clusterIds();
        if ($clusterIds === []) {
            throw new RuntimeException('The application is not placed on a cluster server.');
        }
        $capabilities = $workload->buildCapabilities();
        $architecture = self::targetArchitecture($workload);
        $clusterNodes = Node::query()
            ->whereIn('node_cluster_id', $clusterIds)
            ->withCount(['builds as active_builds_count' => fn ($query) => $query->active()->whereNotNull('started_at')])
            ->orderBy('active_builds_count')
            ->orderBy('id')
            ->get();
        $candidates = $clusterNodes
            ->filter(fn (Node $node): bool => $node->canBuild()
                && $node->canReceiveNetworkCommands()
                && collect($capabilities)->every(fn (string $capability): bool => $node->supportsCapability($capability) === true)
                && data_get($node->metadata, 'builder_running') === true);
        $fitting = $candidates->filter(fn (Node $node): bool => $architecture === null || in_array($node->cpuArchitecture(), [$architecture, null], true));
        if ($candidates->isNotEmpty() && $fitting->isEmpty()) {
            throw new RuntimeException("No builder server in this cluster has the {$architecture} CPU architecture of the servers that run this application. Turn on the builder role on a server with this architecture. Coolify does not build for another architecture.");
        }
        $candidates = $fitting;
        if ($requestedBy !== null && $candidates->isNotEmpty()) {
            $candidates = $candidates->filter(fn (Node $node): bool => Gate::forUser($requestedBy)->allows('placeResource', $node));
            if ($candidates->isEmpty()) {
                throw new NodePlacementDeniedException(self::NOT_ALLOWED);
            }
        }

        // The least busy builder with a free slot (decision 0035). A builder that waits for a new
        // BuildKit configuration, or that has memory or disk pressure, gets no new build.
        $node = $candidates
            ->filter(fn (Node $node): bool => $node->active_builds_count < $node->buildSlots()
                && data_get($node->metadata, 'builder_replace_pending') !== true
                && ! $node->hasBuildPressure())
            ->sortBy([fn (Node $a, Node $b): int => $a->active_builds_count / $a->buildSlots() <=> $b->active_builds_count / $b->buildSlots(), ['id', 'asc']])
            ->first();
        if ($node === null) {
            throw new RuntimeException($candidates->isEmpty() ? self::noBuilderReason($clusterNodes, $capabilities) : NodeBuild::BUILDERS_BUSY);
        }

        return $node;
    }

    /**
     * Why no server can build: the first check that every builder fails, with the names of the servers.
     *
     * @param  Collection<int, Node>  $nodes
     * @param  list<string>  $capabilities
     */
    private static function noBuilderReason(Collection $nodes, array $capabilities): string
    {
        $names = fn ($nodes): string => $nodes->pluck('name')->implode(', ');
        $builders = $nodes->filter(fn (Node $node): bool => $node->canBuild());
        if ($builders->isEmpty()) {
            return 'No server in this cluster has the builder role. Turn on builds for a server on the Servers tab of the cluster.';
        }
        $connected = $builders->filter(fn (Node $node): bool => $node->canReceiveNetworkCommands());
        if ($connected->isEmpty()) {
            return "The builder servers are not connected to Coolify: {$names($builders)}.";
        }
        $missing = collect($capabilities)->reject(fn (string $capability): bool => $connected->contains(fn (Node $node): bool => $node->supportsCapability($capability) === true));
        $capable = $connected->filter(fn (Node $node): bool => collect($capabilities)->every(fn (string $capability): bool => $node->supportsCapability($capability) === true));
        if ($capable->isEmpty()) {
            $features = $missing->isNotEmpty() ? $missing : collect($capabilities);

            return "The Sentinel on the builder servers is too old for this build: {$names($connected)}. Upgrade Sentinel to a version that supports {$features->implode(', ')}.";
        }

        return "The builder of these servers is not running yet: {$names($capable)}. It starts with the next network sync of the cluster.";
    }

    /** Refuses a build of the user before it is queued when the user may not place resources on a builder Node of the cluster. */
    public static function ensureMayBuild(NodeWorkload $workload, User $user): void
    {
        $builders = Node::query()->whereIn('node_cluster_id', $workload->clusterIds())->get()->filter(fn (Node $node): bool => $node->canBuild());
        if ($builders->isNotEmpty() && ! $builders->contains(fn (Node $node): bool => Gate::forUser($user)->allows('placeResource', $node))) {
            throw new NodePlacementDeniedException(self::NOT_ALLOWED);
        }
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
