<?php

namespace App\Actions\Node;

use App\Models\NodeVolume;
use App\Models\NodeVolumeCopy;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Deletes the data of a volume copy on its Node, then the record. The volume row stays locked
 * during the call, so a move that turns this copy into a receiving copy waits for it.
 */
class DeleteNodeVolumeCopy
{
    use AsAction;

    public function handle(NodeVolumeCopy $copy): void
    {
        DB::transaction(function () use ($copy): void {
            $volume = NodeVolume::query()->with('workload')->lockForUpdate()->find($copy->node_volume_id);
            $copy = NodeVolumeCopy::query()->with('node')->lockForUpdate()->find($copy->id);
            if ($volume === null || $copy === null) {
                return;
            }
            // The live data of the volume is never a copy.
            if ($copy->node_id !== $volume->node_id) {
                if ($volume->workload?->hasActiveMove()) {
                    throw new RuntimeException('Wait until the move of this application finishes.');
                }
                DeleteNodeVolumes::run($copy->node, [$volume->podmanName()]);
            }
            $copy->delete();
        });
    }
}
