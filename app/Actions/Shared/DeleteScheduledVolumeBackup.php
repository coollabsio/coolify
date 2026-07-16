<?php

namespace App\Actions\Shared;

use App\Jobs\VolumeBackupJob;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteScheduledVolumeBackup
{
    use AsAction;

    public function handle(ScheduledVolumeBackup $backup, ?Server $server = null): void
    {
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

            $s3Executions = $backup->executions()
                ->with('s3')
                ->where('s3_uploaded', true)
                ->where('s3_storage_deleted', false)
                ->get();

            foreach ($s3Executions->groupBy('s3_storage_id') as $executions) {
                $s3 = $executions->first()->s3;
                if (! $s3) {
                    throw new \RuntimeException('The S3 storage used by an existing backup is unavailable.');
                }

                $filenames = $executions->pluck('filename')->filter()->all();
                if ($filenames !== []) {
                    deleteBackupsS3($filenames, $s3);
                }
            }

            $backup->delete();
        } finally {
            $lock->release();
        }
    }
}
