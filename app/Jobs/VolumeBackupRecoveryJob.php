<?php

namespace App\Jobs;

use App\Models\ScheduledVolumeBackupExecution;
use App\Notifications\VolumeBackup\RecoveryFailed;
use Aws\S3\Exception\S3Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VolumeBackupRecoveryJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ScheduledJobManager dispatches recovery again once an unreachable server is functional, so this job does not retry by itself.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public const CATEGORY_S3_AUTH = 's3_auth';

    public const CATEGORY_S3_BUCKET = 's3_bucket';

    public const CATEGORY_SERVER_UNREACHABLE = 'server_unreachable';

    public const CATEGORY_REMOTE_COMMAND = 'remote_command';

    public const CATEGORY_UNKNOWN = 'unknown';

    private const UNREACHABLE_PATTERNS = [
        'ssh connection failed',
        'connection refused',
        'connection timed out',
        'operation timed out',
        'no route to host',
        'network is unreachable',
        'could not resolve hostname',
        'permission denied (publickey',
        'host key verification failed',
    ];

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
        self::recoverWithBounds($this->execution);
    }

    /**
     * Runs one recovery attempt and records its outcome instead of throwing. An unreachable server waits for the
     * server to be functional again; any other failure needs attention and notifies the team.
     */
    public static function recoverWithBounds(ScheduledVolumeBackupExecution $execution): void
    {
        $current = $execution->fresh();
        if (! $current) {
            return;
        }

        $execution->setRawAttributes($current->getAttributes(), true);

        if (! $execution->hasPendingRecovery() || $execution->recovery_needs_attention) {
            return;
        }

        try {
            $failure = self::runRecoverySteps($execution);
        } catch (Throwable $exception) {
            $failure = ['category' => self::CATEGORY_UNKNOWN, 'exception' => $exception];
        }

        if ($failure) {
            self::recordFailure($execution, $failure['category']);

            return;
        }

        self::recordSuccess($execution);
    }

    /**
     * Recovers stopped containers first, then cleans the partial S3 upload. Each step runs even when the other one
     * fails; the first failure is rethrown after both steps ran.
     */
    public static function recover(ScheduledVolumeBackupExecution $execution): void
    {
        $failure = self::runRecoverySteps($execution);

        if ($failure) {
            throw $failure['exception'];
        }
    }

    /**
     * @return array{category: string, exception: Throwable}|null The first failure, or null when every step succeeded.
     */
    private static function runRecoverySteps(ScheduledVolumeBackupExecution $execution): ?array
    {
        $execution->loadMissing('scheduledVolumeBackup.backupable.resource');
        $failure = null;

        if ($execution->stop_recovery_pending) {
            try {
                self::recoverContainers($execution);
            } catch (Throwable $exception) {
                $failure = ['category' => self::categorizeContainerFailure($exception), 'exception' => $exception];
            }
        }

        if ($execution->s3_cleanup_pending) {
            try {
                self::cleanupS3Upload($execution);
            } catch (Throwable $exception) {
                $failure ??= ['category' => self::categorizeS3Failure($exception), 'exception' => $exception];
            }
        }

        return $failure;
    }

    public static function dispatchCacheKey(int $executionId): string
    {
        return "volume-backup-recovery-dispatched:{$executionId}";
    }

    private static function recordSuccess(ScheduledVolumeBackupExecution $execution): void
    {
        $execution->update([
            'recovery_last_attempt_at' => null,
            'recovery_error' => null,
            'recovery_needs_attention' => false,
        ]);
    }

    private static function recordFailure(ScheduledVolumeBackupExecution $execution, string $category): void
    {
        $needsAttention = $category !== self::CATEGORY_SERVER_UNREACHABLE;
        $enteredNeedsAttention = false;

        $recorded = $execution->getConnection()->transaction(function () use ($execution, $category, $needsAttention, &$enteredNeedsAttention): ?ScheduledVolumeBackupExecution {
            $current = ScheduledVolumeBackupExecution::query()->whereKey($execution->id)->lockForUpdate()->first();
            if (! $current) {
                return null;
            }

            $enteredNeedsAttention = $needsAttention && ! $current->recovery_needs_attention;
            $current->update([
                'recovery_last_attempt_at' => now(),
                'recovery_error' => $category,
                'recovery_needs_attention' => $needsAttention,
            ]);

            return $current;
        });

        if (! $recorded) {
            return;
        }

        $execution->setRawAttributes($recorded->getAttributes(), true);

        Log::channel('scheduled-errors')->warning('Volume backup recovery failed', [
            'execution_id' => $recorded->id,
            'execution_uuid' => $recorded->uuid,
            'category' => $category,
            'needs_attention' => $recorded->recovery_needs_attention,
        ]);

        if ($enteredNeedsAttention) {
            self::notifyTeam($recorded);
        }
    }

    private static function notifyTeam(ScheduledVolumeBackupExecution $execution): void
    {
        try {
            $execution->loadMissing('scheduledVolumeBackup.team');
            $execution->scheduledVolumeBackup?->team?->notify(new RecoveryFailed($execution));
        } catch (Throwable $exception) {
            Log::channel('scheduled-errors')->error('Failed to send volume backup recovery notification', [
                'execution_id' => $execution->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function categorizeS3Failure(Throwable $exception): string
    {
        for ($current = $exception; $current; $current = $current->getPrevious()) {
            if ($current instanceof S3Exception) {
                return match ($current->getAwsErrorCode()) {
                    'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'AccessDenied' => self::CATEGORY_S3_AUTH,
                    'NoSuchBucket' => self::CATEGORY_S3_BUCKET,
                    default => self::CATEGORY_UNKNOWN,
                };
            }
        }

        return self::CATEGORY_UNKNOWN;
    }

    private static function categorizeContainerFailure(Throwable $exception): string
    {
        if ($exception->getCode() === 255) {
            return self::CATEGORY_SERVER_UNREACHABLE;
        }

        $message = strtolower($exception->getMessage());
        foreach (self::UNREACHABLE_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return self::CATEGORY_SERVER_UNREACHABLE;
            }
        }

        return $exception instanceof RuntimeException ? self::CATEGORY_REMOTE_COMMAND : self::CATEGORY_UNKNOWN;
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
        // A container that no longer exists (for example after a redeploy) has nothing to restart; only
        // other inspect or start failures keep the container for another recovery attempt.
        $script = 'status=0; : > '.escapeshellarg($remainingFile).'; '
            .'if [ -f '.escapeshellarg($stateFile).' ]; then while IFS= read -r container; do '
            .'[ -z "$container" ] && continue; running=$(docker inspect --format \'{{.State.Running}}\' "$container" 2>&1) '
            .'|| { case "$running" in *"o such object"*|*"o such container"*) continue ;; esac; '
            .'echo "$container" >> '.escapeshellarg($remainingFile).'; status=1; continue; }; '
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
