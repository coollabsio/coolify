<?php

namespace App\Actions\Node;

use App\Models\NodeVolume;
use App\Models\NodeVolumeCopy;
use App\Models\NodeWorkload;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/** Deletes the data of a detached volume on its Node and on every copy, then the record. */
class DeleteNodeVolumeData
{
    use AsAction;

    public function handle(NodeVolume $volume): void
    {
        DB::transaction(function () use ($volume): void {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($volume->node_workload_id);
            $volume = NodeVolume::query()->with('node')->lockForUpdate()->findOrFail($volume->id);
            if (! $volume->isDetached()) {
                throw new RuntimeException('Remove the volume from the application first.');
            }
            if ($workload->hasActiveMove()) {
                throw new RuntimeException('Wait until the move of this application finishes.');
            }
            if ($workload->latestRevisionMounts($volume)) {
                throw new RuntimeException('Redeploy first. The current configuration of the application still mounts this volume.');
            }

            // Sentinel deletes idempotently, so a retry after a partial failure succeeds.
            $volume->copies()->with('node')->get()->each(fn (NodeVolumeCopy $copy) => DeleteNodeVolumeCopy::run($copy));
            if ($volume->node !== null) {
                try {
                    DeleteNodeVolumes::run($volume->node, [$volume->podmanName()]);
                } catch (Throwable $exception) {
                    throw DeleteNodeVolumes::isInUse($exception)
                        ? new RuntimeException('Redeploy first. A container on '.$volume->node->name.' still uses this volume.')
                        : $exception;
                }
            }
            $volume->delete();
        });
    }
}
