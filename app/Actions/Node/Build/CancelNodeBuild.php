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

    public function handle(NodeBuild $build): void
    {
        $cancelled = NodeBuild::query()
            ->whereKey($build->id)
            ->whereIn('status', NodeBuildStatus::activeValues())
            ->update(['status' => NodeBuildStatus::CANCELLED, 'finished_at' => now(), 'error' => 'Cancelled by a user.']);
        if ($cancelled === 0) {
            return;
        }
        $build->refresh();
        if ($build->node === null || $build->started_at === null) {
            return;
        }
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
