<?php

namespace App\Actions\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteScheduledDatabaseBackup
{
    use AsAction;

    /**
     * Deletes the schedule with its execution history and, when selected, the backup files of the executions.
     */
    public function handle(
        ScheduledDatabaseBackup $backup,
        ?Server $server = null,
        bool $deleteLocalBackups = true,
        bool $deleteS3Backups = true,
    ): void {
        if ($deleteLocalBackups) {
            $localFilenames = $backup->executions()
                ->where('local_storage_deleted', false)
                ->pluck('filename')
                ->filter()
                ->all();

            if ($localFilenames !== []) {
                $server ??= $backup->server();
                if (! $server) {
                    throw new \RuntimeException('The server is unavailable, so local backup files cannot be deleted.');
                }

                deleteBackupsLocally($localFilenames, $server, throwError: true);
            }
        }

        if ($deleteS3Backups) {
            $backup->executions()
                ->whereHas('s3Replicas', fn ($query) => $query->where('s3_uploaded', true)->where('s3_storage_deleted', false))
                ->get()
                ->each(fn (ScheduledDatabaseBackupExecution $execution) => $execution->deleteS3Copies());
        }

        $backup->delete();
    }
}
