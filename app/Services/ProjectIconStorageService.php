<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class ProjectIconStorageService extends AvatarStorageService
{
    public function storeProject(Project $project, UploadedFile $upload): void
    {
        $settings = instanceSettings();
        $storageType = $settings->avatar_storage_type === 's3' && $settings->avatar_s3_storage_id ? 's3' : 'local';
        $s3StorageId = $storageType === 's3' ? $settings->avatar_s3_storage_id : null;
        $disk = $this->disk($storageType, $s3StorageId);
        $path = "project-icons/{$project->uuid}/icon.jpg";

        if (! $disk->put($path, $this->compress($upload))) {
            throw new RuntimeException('Unable to store the project icon.');
        }

        $oldStorageType = $project->icon_storage_type;
        $oldS3StorageId = $project->icon_s3_storage_id;
        $oldPath = $project->icon_path;

        $project->forceFill([
            'icon_path' => $path,
            'icon_storage_type' => $storageType,
            'icon_s3_storage_id' => $s3StorageId,
        ])->save();

        if ($oldPath && ($oldStorageType !== $storageType || $oldS3StorageId !== $s3StorageId)) {
            $this->disk($oldStorageType ?? 'local', $oldS3StorageId)->delete($oldPath);
        }
    }

    public function projectContents(Project $project): ?string
    {
        if (! $project->icon_path) {
            return null;
        }

        try {
            $disk = $this->disk($project->icon_storage_type ?? 'local', $project->icon_s3_storage_id);
        } catch (RuntimeException) {
            return null;
        }

        return $disk->exists($project->icon_path) ? $disk->get($project->icon_path) : null;
    }

    public function deleteProject(Project $project): void
    {
        if ($project->icon_path) {
            try {
                $this->disk($project->icon_storage_type ?? 'local', $project->icon_s3_storage_id)
                    ->delete($project->icon_path);
            } catch (RuntimeException) {
            }
        }

        $project->forceFill([
            'icon_path' => null,
            'icon_storage_type' => null,
            'icon_s3_storage_id' => null,
        ])->save();
    }
}
