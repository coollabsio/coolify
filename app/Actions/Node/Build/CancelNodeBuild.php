<?php

namespace App\Actions\Node\Build;

use App\Enums\NodeBuildStatus;
use App\Models\NodeBuild;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Cancels an active build. The build job sees the status and stops following;
 * a started build container is stopped so it does not push an image.
 */
class CancelNodeBuild
{
    use AsAction;

    public function handle(NodeBuild $build, string $reason = 'Cancelled by a user.'): void
    {
        $cancelled = NodeBuild::query()
            ->whereKey($build->id)
            ->whereIn('status', NodeBuildStatus::activeValues())
            ->update(['status' => NodeBuildStatus::CANCELLED, 'finished_at' => now(), 'error' => $reason]);
        if ($cancelled === 0) {
            return;
        }
        $build->refresh();
        StartWaitingNodeBuild::run($build->node_workload_id);
        if ($build->node !== null && $build->started_at !== null) {
            self::stopContainerOf($build);
        }
        // A cancelled build frees its place in the queue or its build slot (decision 0035), after
        // its container stopped, so Sentinel does not count it against the next build.
        $clusterId = $build->node_cluster_id ?? $build->node?->node_cluster_id;
        if ($clusterId !== null) {
            StartQueuedNodeBuilds::run((int) $clusterId);
        }
    }

    /** Stops the build container on its builder Node; a failure is ignored. */
    public static function stopContainerOf(NodeBuild $build): void
    {
        try {
            RunImageBuild::flux('workload.lifecycle', [
                'server_id' => $build->node->uuid,
                'command_id' => (string) Str::uuid(),
                'name' => $build->containerName(),
                'action' => 'stop',
            ], 150);
        } catch (Throwable) {
            // The job removes the container when it sees the cancelled status.
        }
    }
}
