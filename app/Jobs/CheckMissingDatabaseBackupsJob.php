<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\Database\BackupMissing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckMissingDatabaseBackupsJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        ScheduledDatabaseBackup::query()
            ->with(['team', 'database', 'latest_log'])
            ->where('enabled', true)
            ->where('missing_backup_notification_days', '>', 0)
            ->chunkById(100, function ($backups): void {
                foreach ($backups as $backup) {
                    $this->notifyIfMissing($backup);
                }
            });
    }

    private function notifyIfMissing(ScheduledDatabaseBackup $backup): void
    {
        $lastExecutionAt = $backup->last_execution_at ?? $backup->latest_log?->created_at;
        $lastActivityAt = $lastExecutionAt ?? $backup->created_at;

        if (! $lastActivityAt || $lastActivityAt->isAfter(now()->subDays($backup->missing_backup_notification_days))) {
            return;
        }

        if ($backup->missing_backup_notification_sent_at?->greaterThanOrEqualTo($lastActivityAt)) {
            return;
        }

        if (! $backup->team) {
            Log::warning("Cannot send missing backup notification for backup {$backup->id}: team not found");

            return;
        }

        if ($backup->team->getEnabledChannels('backup_failure') === []) {
            return;
        }

        $backup->team->notify(new BackupMissing($backup, $lastExecutionAt));
        $backup->forceFill(['missing_backup_notification_sent_at' => now()])->save();
    }
}
