<?php

namespace App\Actions\Node;

use App\Models\NodeVolume;
use App\Models\NodeVolumeMount;
use App\Models\NodeWorkload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Renames a volume or changes the path where a workload mounts it (decision 0029). The data
 * stays: the Podman name of a volume is its UUID. The next deployment mounts the volume at the
 * new path. For a stack volume (decision 0024), the path is the one of this service.
 */
class UpdateNodeVolume
{
    use AsAction;

    /** @throws ValidationException `name` or `mount_path`. */
    public function handle(NodeWorkload $workload, NodeVolume $volume, ?string $name, ?string $mountPath): NodeVolume
    {
        if ($name !== null) {
            Validator::make(['name' => $name], ['name' => CreateNodeVolume::nameRules()], CreateNodeVolume::nameMessages())->validate();
        }
        $path = null;
        if ($mountPath !== null) {
            $path = CreateNodeVolume::normalizeMountPath($mountPath)
                ?? throw ValidationException::withMessages(['mount_path' => CreateNodeVolume::MOUNT_PATH_ERROR]);
        }

        return DB::transaction(function () use ($workload, $volume, $name, $path): NodeVolume {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            $volume = NodeVolume::query()->lockForUpdate()->findOrFail($volume->id);
            if ($workload->hasActiveMove()) {
                throw ValidationException::withMessages(['mount_path' => 'Wait until the move of this application finishes.']);
            }
            $mount = $volume->isStackVolume()
                ? NodeVolumeMount::query()->where('node_volume_id', $volume->id)->where('node_workload_id', $workload->id)->first()
                : null;
            if ($volume->isStackVolume() ? $mount === null : ($volume->node_workload_id !== $workload->id || $volume->isDetached())) {
                throw ValidationException::withMessages(['volume' => 'This application does not mount the volume.']);
            }
            if ($path !== null && collect(ManageNodeVolumeBackups::volumesOf($workload))
                ->contains(fn (NodeVolume $other): bool => $other->id !== $volume->id && $other->mount_path === $path)) {
                throw ValidationException::withMessages(['mount_path' => "Another volume is already mounted at {$path}."]);
            }
            if ($name !== null) {
                $volume->update(['name' => trim($name)]);
            }
            if ($path !== null) {
                $mount !== null ? $mount->update(['mount_path' => $path]) : $volume->update(['mount_path' => $path]);
            }

            return $volume->refresh();
        });
    }
}
