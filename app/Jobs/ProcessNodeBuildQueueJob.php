<?php

namespace App\Jobs;

use App\Actions\Node\Build\CancelNodeBuild;
use App\Actions\Node\Build\StartQueuedNodeBuilds;
use App\Actions\Node\Build\StartWaitingNodeBuild;
use App\Actions\Node\NotifyNodeDeployment;
use App\Enums\NodeBuildStatus;
use App\Models\Node;
use App\Models\NodeBuild;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs each minute (decision 0035). It fails the builds whose job stopped, so their build slot is
 * free again, then starts the waiting builds of every cluster that has a free slot. This covers a
 * builder that comes back, a busy refusal of Sentinel, and a lost dispatch. It also resumes the
 * stack deploys whose builds all ended (DeployNodeStackJob::resumeStalled()).
 */
class ProcessNodeBuildQueueJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A running build writes a heartbeat each 30 s; without one for this time, its job stopped. */
    public const HEARTBEAT_STALE_MINUTES = 20;

    /** A claimed build whose job did not start in this time lost its dispatch. */
    public const CLAIM_STALE_MINUTES = 120;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $clusterIds = collect();
        NodeBuild::query()
            ->whereNotNull('node_id')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('status', NodeBuildStatus::RUNNING->value)->where('updated_at', '<', now()->subMinutes(self::HEARTBEAT_STALE_MINUTES)))
                ->orWhere(fn ($query) => $query->where('status', NodeBuildStatus::QUEUED->value)->where('started_at', '<', now()->subMinutes(self::CLAIM_STALE_MINUTES))))
            ->with('node')
            ->get()
            ->each(function (NodeBuild $build) use ($clusterIds): void {
                $failed = NodeBuild::query()->whereKey($build->id)->whereIn('status', NodeBuildStatus::activeValues())
                    ->where('updated_at', $build->updated_at)
                    ->update(['status' => NodeBuildStatus::FAILED, 'finished_at' => now(), 'error' => 'The build job stopped before the build ended. Start the build again.']);
                if ($failed === 0) {
                    return;
                }
                NotifyNodeDeployment::forFailedBuild($build);
                if ($build->node !== null) {
                    CancelNodeBuild::stopContainerOf($build);
                }
                $clusterIds->push($build->node_cluster_id ?? $build->node?->node_cluster_id);
                StartWaitingNodeBuild::run($build->node_workload_id);
                DeployNodeStackJob::resumeAfter($build);
            });
        DeployNodeStackJob::resumeStalled();

        $pending = Node::query()->where('metadata->builder_replace_pending', true)->pluck('node_cluster_id');
        $clusterIds->merge(StartQueuedNodeBuilds::clustersWithQueue())->merge($pending)
            ->filter()->map(fn ($id): int => (int) $id)->unique()
            ->each(fn (int $clusterId) => StartQueuedNodeBuilds::run($clusterId));
    }
}
