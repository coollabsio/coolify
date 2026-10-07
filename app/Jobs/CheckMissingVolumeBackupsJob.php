<?php

namespace App\Jobs;

use App\Models\NotificationThrottle;
use App\Models\ScheduledVolumeBackup;
use App\Notifications\VolumeBackup\BackupMissing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckMissingVolumeBackupsJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800;

    /**
     * Releases the unique lock of a killed worker after one run (the timeout), so the next hourly run is not
     * blocked until the queue retry_after.
     */
    public int $uniqueFor = 1800;

    public function handle(): void
    {
        ScheduledVolumeBackup::query()
            ->with(['team', 'latestExecution'])
            ->where('enabled', true)
            ->where('missing_backup_notification_days', '>', 0)
            ->chunkById(100, function ($backups): void {
                foreach ($backups as $backup) {
                    $this->notifyIfMissing($backup);
                }
            });
    }

    private function notifyIfMissing(ScheduledVolumeBackup $backup): void
    {
        $lastExecutionAt = $backup->latestExecution?->created_at;
        $lastActivityAt = $lastExecutionAt ?? $backup->created_at;

        if (! $lastActivityAt || $lastActivityAt->isAfter(now()->subDays($backup->missing_backup_notification_days))) {
            return;
        }

        if (! $backup->team) {
            Log::warning("Cannot send missing volume backup notification for backup {$backup->id}: team not found");

            return;
        }

        if ($backup->team->getEnabledChannels('backup_failure') === []) {
            return;
        }

        // Send once for each period without backup activity.
        NotificationThrottle::sendOnce(
            $backup,
            BackupMissing::class,
            $lastActivityAt,
            fn () => $backup->team->notify(new BackupMissing($backup, $lastExecutionAt)),
        );
    }
}
