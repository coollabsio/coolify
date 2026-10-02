<?php

namespace App\Jobs;

use App\Models\ScheduledVolumeBackupExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VolumeBackupRecoveryJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ScheduledJobManager dispatches pending recovery again, so this job does not retry by itself.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public ScheduledVolumeBackupExecution $execution)
    {
        $this->onQueue(crons_queue());
    }

    public function uniqueId(): string
    {
        return (string) $this->execution->id;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('volume-backup-'.$this->execution->scheduled_volume_backup_id))
                ->shared()
                ->expireAfter(300)
                ->dontRelease(),
            (new WithoutOverlapping('volume-backup-recovery-'.$this->execution->id))
                ->expireAfter(300)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        self::recover($this->execution);
    }

    public static function recover(ScheduledVolumeBackupExecution $execution): void
    {
        $execution->loadMissing('scheduledVolumeBackup.backupable.resource');

        if ($execution->stop_recovery_pending) {
            self::recoverContainers($execution);
        }

        if ($execution->s3_cleanup_pending) {
            self::cleanupS3Upload($execution);
        }
    }

    private static function recoverContainers(ScheduledVolumeBackupExecution $execution): void
    {
        $server = $execution->scheduledVolumeBackup?->server();

        if (! $server) {
            self::skipRecovery(
                $execution,
                ['stop_container_ids' => null, 'stop_recovery_pending' => false],
                'Container recovery was skipped because the server or resource no longer exists.',
            );

            return;
        }

        $stateFile = self::stateFile($execution);
        $output = instant_remote_process(
            ['cat '.escapeshellarg($stateFile).' 2>/dev/null || true'],
            $server,
            disableMultiplexing: true,
        );
        $containers = collect(preg_split('/\s+/', trim((string) $output)))
            ->filter(fn (string $container): bool => preg_match('/^[a-f0-9]{6,64}$/i', $container) === 1)
            ->values()
            ->all();
        $execution->update(['stop_container_ids' => $containers]);

        $remainingFile = $stateFile.'.remaining';
        $script = 'status=0; : > '.escapeshellarg($remainingFile).'; '
            .'if [ -f '.escapeshellarg($stateFile).' ]; then while IFS= read -r container; do '
            .'[ -z "$container" ] && continue; running=$(docker inspect --format \'{{.State.Running}}\' "$container" 2>/dev/null) '
            .'|| { echo "$container" >> '.escapeshellarg($remainingFile).'; status=1; continue; }; '
            .'if [ "$running" != true ] && ! docker start "$container" >/dev/null; then echo "$container" >> '
            .escapeshellarg($remainingFile).'; status=1; fi; done < '.escapeshellarg($stateFile).'; fi; '
            .'if [ -s '.escapeshellarg($remainingFile).' ]; then mv '.escapeshellarg($remainingFile).' '.escapeshellarg($stateFile)
            .'; else rm -f '.escapeshellarg($stateFile).' '.escapeshellarg($remainingFile).'; fi; exit $status';

        instant_remote_process(['sh -c '.escapeshellarg($script)], $server, disableMultiplexing: true);
        $execution->update([
            'stop_container_ids' => null,
            'stop_recovery_pending' => false,
        ]);
    }

    public static function cleanupS3Upload(ScheduledVolumeBackupExecution $execution): void
    {
        $execution->loadMissing('s3');
        $s3 = $execution->s3;

        if (! $s3 || blank($execution->filename)) {
            self::skipRecovery(
                $execution,
                ['s3_cleanup_pending' => false],
                'S3 upload cleanup was skipped because the S3 storage or backup filename no longer exists.',
            );

            return;
        }

        deleteBackupsS3($execution->filename, $s3);
        $execution->update([
            's3_cleanup_pending' => false,
            's3_storage_deleted' => true,
        ]);
    }

    /**
     * Clears a pending recovery that cannot succeed, so it does not retry forever and block new backups.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function skipRecovery(ScheduledVolumeBackupExecution $execution, array $attributes, string $reason): void
    {
        Log::channel('scheduled-errors')->warning($reason, ['execution_id' => $execution->id]);

        $execution->update([
            ...$attributes,
            'message' => trim(($execution->message ?? '').' '.$reason),
        ]);
    }

    public static function stateFile(ScheduledVolumeBackupExecution $execution): string
    {
        return '/tmp/coolify-volume-backup-'.$execution->uuid.'.stopped';
    }
}
