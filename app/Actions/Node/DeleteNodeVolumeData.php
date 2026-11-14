<?php

namespace App\Actions\Node;

use App\Models\NodeStack;
use App\Models\NodeVolume;
use App\Models\NodeVolumeCopy;
use App\Models\NodeWorkload;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Deletes the data of a detached volume on its Node and on every copy, then the record. A stack
 * volume (decision 0024) is deleted only when no service of the stack mounts it.
 */
class DeleteNodeVolumeData
{
    use AsAction;

    public function handle(NodeVolume $volume): void
    {
        DB::transaction(function () use ($volume): void {
            if ($volume->isStackVolume()) {
                NodeStack::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($volume->node_stack_id);
                $workloads = NodeWorkload::query()->withoutGlobalScopes()->where('node_stack_id', $volume->node_stack_id)->get();
            } else {
                $workloads = collect([NodeWorkload::query()->lockForUpdate()->findOrFail($volume->node_workload_id)]);
            }
            $volume = NodeVolume::query()->with('node')->lockForUpdate()->findOrFail($volume->id);
            if ($volume->isStackVolume()) {
                $services = $volume->mountingWorkloads()->withoutGlobalScopes()->pluck('stack_service');
                if ($services->isNotEmpty()) {
                    throw new RuntimeException('The services '.$services->implode(', ').' still mount this volume. Remove it from them first.');
                }
            } elseif (! $volume->isDetached()) {
                throw new RuntimeException('Remove the volume from the application first.');
            }
            if ($workloads->contains(fn (NodeWorkload $workload): bool => $workload->hasActiveMove())) {
                throw new RuntimeException('Wait until the move of this application finishes.');
            }
            if ($volume->scheduledBackups()->exists()) {
                throw new RuntimeException('Delete the backup schedule of this volume and its archives first.');
            }
            if ($workloads->contains(fn (NodeWorkload $workload): bool => $workload->latestRevisionMounts($volume))) {
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
