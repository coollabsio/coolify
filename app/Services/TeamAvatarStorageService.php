<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class TeamAvatarStorageService extends AvatarStorageService
{
    public function storeTeam(Team $team, UploadedFile $upload): void
    {
        $settings = instanceSettings();
        $storageType = $settings->avatar_storage_type === 's3' && $settings->avatar_s3_storage_id ? 's3' : 'local';
        $s3StorageId = $storageType === 's3' ? $settings->avatar_s3_storage_id : null;
        $disk = $this->disk($storageType, $s3StorageId);
        $path = "team-avatars/{$team->id}/avatar.jpg";

        if (! $disk->put($path, $this->compress($upload))) {
            throw new RuntimeException('Unable to store the team avatar.');
        }

        $oldStorageType = $team->avatar_storage_type;
        $oldS3StorageId = $team->avatar_s3_storage_id;
        $oldPath = $team->avatar_path;

        $team->forceFill([
            'avatar_path' => $path,
            'avatar_storage_type' => $storageType,
            'avatar_s3_storage_id' => $s3StorageId,
        ])->save();

        if ($oldPath && ($oldStorageType !== $storageType || $oldS3StorageId !== $s3StorageId)) {
            $this->disk($oldStorageType ?? 'local', $oldS3StorageId)->delete($oldPath);
        }
    }

    public function teamContents(Team $team): ?string
    {
        if (! $team->avatar_path) {
            return null;
        }

        try {
            $disk = $this->disk($team->avatar_storage_type ?? 'local', $team->avatar_s3_storage_id);
        } catch (RuntimeException) {
            return null;
        }

        return $disk->exists($team->avatar_path) ? $disk->get($team->avatar_path) : null;
    }

    public function deleteTeam(Team $team): void
    {
        $this->deleteTeamFile($team);

        $team->forceFill([
            'avatar_path' => null,
            'avatar_storage_type' => null,
            'avatar_s3_storage_id' => null,
        ])->save();
    }

    public function deleteTeamFile(Team $team): void
    {
        if (! $team->avatar_path) {
            return;
        }

        try {
            $this->disk($team->avatar_storage_type ?? 'local', $team->avatar_s3_storage_id)
                ->delete($team->avatar_path);
        } catch (RuntimeException) {
        }
    }
}
