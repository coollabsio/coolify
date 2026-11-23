<?php

namespace App\Actions\Node\Build;

use App\Exceptions\NodePlacementDeniedException;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Picks the builder Node of a build: a Node of the workload's cluster with the
 * builder role and a running BuildKit, with the fewest active builds. A
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
     * Picks the builder Node of the build and claims it for the build in one transaction. The lock
     * on the Nodes of the cluster makes a second job wait until this claim is committed, so two
     * builds never pick the same free builder Node. The claim sets `node_id` and `started_at`;
     * RunImageBuild then starts the build there.
     */
    public function claim(NodeBuild $build): Node
    {
        return DB::transaction(function () use ($build): Node {
            Node::query()->whereIn('node_cluster_id', $build->workload->clusterIds())->orderBy('id')->lockForUpdate()->pluck('id');
            $node = $this->handle($build->workload, $build->requestedBy, $build->id);
            NodeBuild::query()->whereKey($build->id)->update(['node_id' => $node->id, 'started_at' => now()]);

            return $node;
        });
    }

    /**
     * @param  ?int  $exceptBuildId  A build that does not count as active: the build that a retried job claimed before.
     */
    public function handle(NodeWorkload $workload, ?User $requestedBy = null, ?int $exceptBuildId = null): Node
    {
        $clusterIds = $workload->clusterIds();
        if ($clusterIds === []) {
            throw new RuntimeException('The application is not placed on a cluster server.');
        }
        $capabilities = $workload->buildCapabilities();
        $architecture = self::targetArchitecture($workload);
        $clusterNodes = Node::query()
            ->whereIn('node_cluster_id', $clusterIds)
            ->withCount(['builds as active_builds_count' => fn ($query) => $query->active()->whereNotNull('started_at')
                ->when($exceptBuildId !== null, fn ($query) => $query->whereKeyNot($exceptBuildId))])
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

        // One build at a time on each builder Node; the job waits for a free one.
        $node = $candidates->first(fn (Node $node): bool => $node->active_builds_count === 0);
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
