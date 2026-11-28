<?php

namespace App\Actions\Node\Build;

use App\Actions\Node\NotifyNodeDeployment;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Enums\NodeBuildStatus;
use App\Jobs\DeployNodeStackJob;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeCluster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Starts the builds that wait for a free builder in the FIFO queue of a cluster (decision 0035).
 * A waiting build holds no Horizon worker: this action claims a slot for it and dispatches its job
 * again. For each build it runs SelectBuilderNode again, so the architecture, the capabilities, and
 * the `placeResource` check of the user who started the build apply when the build starts. A build
 * that no free builder fits stays in the queue and does not block the builds after it.
 *
 * It runs when a build ends, fails, or is cancelled, when a builder reports a running BuildKit, and
 * each minute from the scheduler. A second run does nothing.
 */
class StartQueuedNodeBuilds
{
    use AsAction;

    /** The waiting builds that a cluster accepts. The next build is refused. */
    public const MAX_QUEUED = 50;

    public const QUEUE_FULL = 'The build queue of this cluster is full. Wait for a build to end, or cancel a waiting build.';

    /**
     * @param  ?NodeBuild  $enqueue  A build whose job is ready to build: it joins the queue in the same transaction.
     * @return ?Node The builder Node that `$enqueue` claimed. Null when it waits; its job then ends.
     */
    public function handle(int $clusterId, ?NodeBuild $enqueue = null): ?Node
    {
        $failed = collect();
        $claimed = collect();
        $node = DB::transaction(function () use ($clusterId, $enqueue, &$failed, &$claimed): ?Node {
            // The cluster row is the lock of its queue; StartNodeBuild takes it for the queue limit.
            $cluster = NodeCluster::query()->withoutGlobalScopes()->lockForUpdate()->find($clusterId);
            if ($cluster === null) {
                return null;
            }
            if ($enqueue !== null) {
                NodeBuild::query()->whereKey($enqueue->id)->whereNull('builder_queued_at')
                    ->update(['node_cluster_id' => $clusterId, 'builder_queued_at' => now()]);
            }
            $queue = NodeBuild::query()->builderQueue($clusterId)->with(['workload', 'requestedBy'])->get();
            if ($queue->isEmpty()) {
                return null;
            }
            // The claim of a slot locks every Node that a build of the queue can use, in one order.
            $clusterIds = $queue->flatMap(fn (NodeBuild $build): array => $build->workload?->clusterIds() ?? [])->push($clusterId)->unique()->values();
            Node::query()->whereIn('node_cluster_id', $clusterIds)->orderBy('id')->lockForUpdate()->pluck('id');

            foreach ($queue as $build) {
                if ($build->workload === null) {
                    $failed->push([$build, 'The application of this build was deleted.']);

                    continue;
                }
                try {
                    $builder = SelectBuilderNode::run($build->workload, $build->requestedBy);
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() !== NodeBuild::BUILDERS_BUSY) {
                        $failed->push([$build, $exception->getMessage()]);
                    }

                    continue;
                }
                NodeBuild::query()->whereKey($build->id)->update(['node_id' => $builder->id, 'started_at' => now(), 'builder_queued_at' => null]);
                $claimed->put($build->id, $builder);
            }

            return $enqueue === null ? null : $claimed->get($enqueue->id);
        });

        $this->failBuilds($failed);
        NodeBuild::query()->whereIn('id', $claimed->keys()->reject(fn (int $id): bool => $id === $enqueue?->id))->get()
            ->each(fn (NodeBuild $build) => StartNodeBuild::dispatchBuild($build));
        $this->replacePendingBuilders($clusterId);

        return $node;
    }

    /**
     * Puts a build that Sentinel refused at its limit back in the queue, at its old position. The
     * scheduler tries it again within a minute, so a builder that refuses does not loop.
     */
    public static function requeue(NodeBuild $build): void
    {
        NodeBuild::query()->whereKey($build->id)->whereIn('status', NodeBuildStatus::activeValues())->update([
            'status' => NodeBuildStatus::QUEUED,
            'node_id' => null,
            'started_at' => null,
            'builder_queued_at' => $build->builder_queued_at ?? now(),
        ]);
    }

    /** The clusters with a waiting build. */
    public static function clustersWithQueue(): Collection
    {
        return NodeBuild::query()
            ->where('status', NodeBuildStatus::QUEUED->value)
            ->whereNull('node_id')
            ->whereNotNull('builder_queued_at')
            ->where('is_waiting', false)
            ->distinct()
            ->pluck('node_cluster_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);
    }

    /** @param  Collection<int, array{0: NodeBuild, 1: string}>  $failed */
    private function failBuilds(Collection $failed): void
    {
        foreach ($failed as [$build, $reason]) {
            $changed = NodeBuild::query()->whereKey($build->id)->whereIn('status', NodeBuildStatus::activeValues())
                ->update(['status' => NodeBuildStatus::FAILED, 'finished_at' => now(), 'error' => mb_substr($reason, 0, 2000), 'builder_queued_at' => null]);
            if ($changed > 0) {
                NotifyNodeDeployment::forFailedBuild($build);
                if ($build->node_workload_id !== null) {
                    StartWaitingNodeBuild::run($build->node_workload_id);
                }
                DeployNodeStackJob::resumeAfter($build);
            }
        }
    }

    /**
     * A builder that waits for a new BuildKit configuration (Sentinel replaces BuildKit only while
     * no build runs) gets a new network revision once its last build ended.
     */
    private function replacePendingBuilders(int $clusterId): void
    {
        $pending = Node::query()->where('node_cluster_id', $clusterId)->get()
            ->filter(fn (Node $node): bool => data_get($node->metadata, 'builder_replace_pending') === true
                && ! $node->builds()->active()->whereNotNull('started_at')->exists());
        $cluster = NodeCluster::query()->withoutGlobalScopes()->find($clusterId);
        if ($pending->isNotEmpty() && $cluster !== null) {
            $pending->each(fn (Node $node) => $node->mergeMetadata(['builder_replace_pending' => false]));
            QueueNodeClusterNetworkRevision::run($cluster);
        }
    }
}
