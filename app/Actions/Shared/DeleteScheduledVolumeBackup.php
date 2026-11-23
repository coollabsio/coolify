<?php

namespace App\Actions\Shared;

use App\Jobs\VolumeBackupJob;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteScheduledVolumeBackup
{
    use AsAction;

    public function handle(
        ScheduledVolumeBackup $backup,
        ?Server $server = null,
        bool $deleteLocalArchives = true,
        bool $deleteS3Archives = true,
    ): void {
        $lock = Cache::lock(VolumeBackupJob::lockKey($backup->id), $backup->timeout + 300);

        if (! $lock->get()) {
            throw new \RuntimeException('Wait for the queued or running storage backup to finish before deleting this schedule.');
        }

        try {
            if ($backup->executions()
                ->where(fn ($query) => $query
                    ->where('status', 'running')
                    ->orWhere('stop_recovery_pending', true)
                    ->orWhere('s3_cleanup_pending', true))
                ->exists()) {
                throw new \RuntimeException('Wait for the running storage backup and recovery operations to finish before deleting this schedule.');
            }

            if ($deleteLocalArchives) {
                $localFilenames = $backup->executions()
                    ->where('local_storage_deleted', false)
                    ->pluck('filename')
                    ->filter()
                    ->all();

                if ($localFilenames !== []) {
                    $server ??= $backup->server();
                    if (! $server) {
                        throw new \RuntimeException('The server is unavailable, so local backup archives cannot be deleted.');
                    }

                    deleteBackupsLocally($localFilenames, $server, throwError: true);
                }
            }

            if ($deleteS3Archives) {
                $backup->executions()
                    ->whereHas('s3Replicas', fn ($query) => $query->where('s3_uploaded', true)->where('s3_storage_deleted', false))
                    ->get()
                    ->each(fn (ScheduledVolumeBackupExecution $execution) => $execution->deleteS3Copies());
            }

            $backup->delete();
        } finally {
            $lock->release();
        }
    }
}
