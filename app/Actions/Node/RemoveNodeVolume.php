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
 * A stack volume (decision 0024) is removed from one service: the next deployment of that service
 * no longer mounts it. The volume stays for the other services, and on the stack page once no
 * service mounts it.
 *
 * @return 'deleted'|'detached'
 */
class RemoveNodeVolume
{
    use AsAction;

    public function handle(NodeVolume $volume, ?NodeWorkload $service = null): string
    {
        if ($volume->isStackVolume()) {
            return $this->removeFromService($volume, $service);
        }

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

    private function removeFromService(NodeVolume $volume, ?NodeWorkload $service): string
    {
        if ($service === null) {
            throw new RuntimeException('Select the service that no longer mounts this volume.');
        }

        return DB::transaction(function () use ($volume, $service): string {
            $service = NodeWorkload::query()->lockForUpdate()->findOrFail($service->id);
            if ($service->hasActiveMove()) {
                throw new RuntimeException('Wait until the move of this service finishes.');
            }
            if ($volume->mounts()->where('node_workload_id', $service->id)->delete() === 0) {
                throw new RuntimeException('This service does not mount the volume.');
            }

            return 'detached';
        });
    }
}
