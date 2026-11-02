<?php

namespace App\Jobs;

use App\Actions\Node\DeleteNodeVolumeCopy;
use App\Enums\NodeVolumeCopyState;
use App\Models\NodeVolumeCopy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Deletes retained volume copies after their retention (decision 0007). A copy on a Node that is
 * not reachable now stays until a later run.
 */
class DeleteExpiredNodeVolumeCopiesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(): void
    {
        NodeVolumeCopy::query()
            ->where('state', NodeVolumeCopyState::RETAINED)
            ->where('delete_after', '<=', now())
            ->with('node')
            ->chunkById(100, function ($copies): void {
                foreach ($copies as $copy) {
                    if ($copy->node === null || ! $copy->node->is_usable || ! $copy->node->hasRecentFluxHeartbeat()) {
                        continue;
                    }
                    try {
                        DeleteNodeVolumeCopy::run($copy);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }
}
