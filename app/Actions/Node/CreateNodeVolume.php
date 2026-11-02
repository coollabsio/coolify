<?php

namespace App\Actions\Node;

use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Adds a volume to a cluster application. The volume has no Node until the next deployment
 * creates it, and the next deployment mounts it.
 */
class CreateNodeVolume
{
    use AsAction;

    public function handle(NodeWorkload $workload, string $name, string $mountPath): NodeVolume
    {
        $path = self::normalizeMountPath($mountPath);
        if ($path === null) {
            throw ValidationException::withMessages(['mount_path' => 'Enter an absolute path inside the container, for example /data. It cannot be /, contain .., a comma, or a null byte.']);
        }

        return DB::transaction(function () use ($workload, $name, $path): NodeVolume {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            if ($workload->nodes()->count() > 1) {
                throw ValidationException::withMessages(['mount_path' => 'This application runs on more than one server. A volume lives on one server, so the application must run on one server only.']);
            }
            if ($workload->hasActiveMove()) {
                throw ValidationException::withMessages(['mount_path' => 'Wait until the move of this application finishes.']);
            }
            $volumes = $workload->volumes()->whereNull('detached_at')->get();
            if ($volumes->count() >= NodeVolume::MAX_PER_WORKLOAD) {
                throw ValidationException::withMessages(['mount_path' => 'An application can have at most '.NodeVolume::MAX_PER_WORKLOAD.' volumes.']);
            }
            if ($volumes->contains('mount_path', $path)) {
                throw ValidationException::withMessages(['mount_path' => "Another volume is already mounted at {$path}."]);
            }

            return $workload->volumes()->create([
                'team_id' => $workload->team_id,
                'name' => trim($name),
                'mount_path' => $path,
            ]);
        });
    }

    /**
     * An absolute, normalized container path, or null when the path cannot be a mount target:
     * relative, `/`, with `..`, a comma (the Podman `--mount` separator), or a null byte.
     */
    public static function normalizeMountPath(string $path): ?string
    {
        $path = trim($path);
        if (! str_starts_with($path, '/') || str_contains($path, ',') || str_contains($path, "\0") || mb_strlen($path) > 4096) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== '' && $segment !== '.'));
        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        return '/'.implode('/', $segments);
    }
}
