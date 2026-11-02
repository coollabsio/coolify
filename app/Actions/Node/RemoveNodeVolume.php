<?php

namespace App\Actions\Node;

use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Removes a volume from the configuration of its application. A volume that was never created
 * is deleted. Otherwise it is detached: the next deployment no longer mounts it, and its data
 * stays until the user deletes it.
 *
 * @return 'deleted'|'detached'
 */
class RemoveNodeVolume
{
    use AsAction;

    public function handle(NodeVolume $volume): string
    {
        return DB::transaction(function () use ($volume): string {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($volume->node_workload_id);
            $volume = NodeVolume::query()->lockForUpdate()->findOrFail($volume->id);
            if ($workload->hasActiveMove()) {
                throw new RuntimeException('Wait until the move of this application finishes.');
            }
            if ($volume->node_id === null && ! $workload->latestRevisionMounts($volume)) {
                $volume->delete();

                return 'deleted';
            }
            $volume->update(['detached_at' => $volume->detached_at ?? now()]);

            return 'detached';
        });
    }
}
