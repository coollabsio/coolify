<?php

namespace App\Jobs;

use App\Events\BackupCreated;
use App\Models\LocalPersistentVolume;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Rules\SafeWebhookUrl;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class VolumeBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 1;

    public int $timeout = 3600;

    private ?ScheduledVolumeBackupExecution $execution = null;

    public function __construct(public ScheduledVolumeBackup $backup)
    {
        $this->onQueue(crons_queue());
        $this->timeout = $backup->timeout ?? 3600;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('volume-backup-'.$this->backup->id))
                ->shared()
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public static function lockKey(int $backupId): string
    {
        return 'laravel-queue-overlap:volume-backup-'.$backupId;
    }

    public function handle(): void
    {
        $this->backup->loadMissing(['backupable.resource', 'team', 's3']);
        $server = $this->backup->server();
        $target = $this->backup->backupable;
        $team = $this->backup->team;

        if (! $server || ! $target || ! $team) {
            throw new \RuntimeException('The storage backup resource, team, or server no longer exists.');
        }

        $this->execution = $this->backup->executions()->create([
            's3_storage_id' => $this->backup->save_s3 ? $this->backup->s3_storage_id : null,
        ]);
        BackupCreated::dispatch($team->id);

        $backupDirectory = backup_dir().'/volumes/'.str($team->name)->slug().'-'.$team->id.'/'.$target->uuid;
        $filename = str($this->backup->targetType())->lower().'-'.str($this->backup->targetName())->slug().'-'.Carbon::now()->timestamp.'.tar.gz';
        $backupLocation = $backupDirectory.'/'.$filename;
        $this->execution->update(['filename' => $backupLocation]);

        try {
            $source = $this->backup->sourcePath();
            $containerName = 'volume-backup-'.$this->execution->uuid;
            $image = coolifyHelperImage().':'.getHelperVersion();
            $compressionCpuPercentage = $this->compressionCpuPercentage($server);
            $this->logCompressorInDevelopment($image, $server, $compressionCpuPercentage);
            $verifySourceCommand = $target instanceof LocalPersistentVolume && blank($target->host_path)
                ? 'docker volume inspect '.escapeshellarg($source).' >/dev/null'
                : 'test -d '.escapeshellarg($source);

            $archiveScript = "compressor='gzip -3'; "
                ."if command -v pigz >/dev/null 2>&1; then compressor=\"pigz -3 -p \$(( (\$(nproc) * {$compressionCpuPercentage} + 99) / 100 ))\"; fi; "
                .'tar -I "$compressor" -cf - -C /volume .';
            $archiveCommand = 'docker run --rm --name '.escapeshellarg($containerName)
                .' -v '.escapeshellarg($source.':/volume:ro')
                .' '.escapeshellarg($image)
                .' sh -c '.escapeshellarg($archiveScript)
                .' > '.escapeshellarg($backupLocation);

            if ($this->backup->stop_during_backup) {
                $containers = $this->containersUsingVolume($source, $server);
                if ($containers !== []) {
                    $this->execution->update(['stop_recovery_pending' => true]);
                    $archiveCommand = $this->archiveWithStoppedContainers(
                        $archiveCommand,
                        $containers,
                        VolumeBackupRecoveryJob::stateFile($this->execution),
                    );
                }
            }

            instant_remote_process([
                $verifySourceCommand,
                'mkdir -p '.escapeshellarg($backupDirectory),
                $archiveCommand,
            ], $server, timeout: $this->timeout, disableMultiplexing: true);
            $this->execution->update([
                'stop_container_ids' => null,
                'stop_recovery_pending' => false,
            ]);

            $size = (int) instant_remote_process(
                ['du -b '.escapeshellarg($backupLocation).' | cut -f1'],
                $server,
                disableMultiplexing: true,
            );

            if ($size <= 0) {
                throw new \RuntimeException('The storage backup archive is empty or was not created.');
            }

            $warning = null;
            $s3Uploaded = null;
            $s3CleanupPending = false;
            $localStorageDeleted = false;

            if ($this->backup->save_s3) {
                $s3CleanupPending = true;
                $this->execution->update(['s3_cleanup_pending' => true]);

                try {
                    $this->uploadToS3($backupLocation, $backupDirectory, $server);
                    $s3Uploaded = true;
                    $s3CleanupPending = false;
                } catch (Throwable $exception) {
                    $s3Uploaded = false;
                    $warning = 'S3 upload failed: '.$exception->getMessage();

                    try {
                        VolumeBackupRecoveryJob::cleanupS3Upload($this->execution);
                        $s3CleanupPending = false;
                    } catch (Throwable $cleanupException) {
                        VolumeBackupRecoveryJob::dispatch($this->execution);
                        $warning .= ' Partial S3 upload cleanup failed: '.$cleanupException->getMessage();
                    }
                }

                if ($s3Uploaded && $this->backup->disable_local_backup) {
                    try {
                        deleteBackupsLocally($backupLocation, $server, throwError: true);
                        $localStorageDeleted = true;
                    } catch (Throwable $exception) {
                        $warning = 'S3 upload succeeded, but the local archive could not be deleted: '.$exception->getMessage();
                    }
                }
            }

            $this->execution->update([
                'status' => 'success',
                'message' => $warning,
                'size' => $size,
                'filename' => $backupLocation,
                's3_uploaded' => $s3Uploaded,
                's3_cleanup_pending' => $s3CleanupPending,
                'local_storage_deleted' => $localStorageDeleted,
            ]);

            try {
                $this->removeExpiredBackups($server);
            } catch (Throwable $exception) {
                Log::channel('scheduled-errors')->warning('Volume backup retention cleanup failed', [
                    'backup_id' => $this->backup->id,
                    'execution_id' => $this->execution->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        } catch (Throwable $exception) {
            $recoveryError = $this->recoverIncompleteBackup($this->execution);
            $archiveDeleted = false;

            try {
                deleteBackupsLocally($backupLocation, $server, throwError: true);
                $archiveDeleted = true;
            } catch (Throwable $cleanupException) {
                $recoveryError .= ' Archive cleanup failed: '.$cleanupException->getMessage();
            }

            $s3CleanupPending = $this->execution->fresh()->s3_cleanup_pending;

            $this->execution->update([
                'status' => 'failed',
                'message' => $exception->getMessage().$recoveryError,
                'filename' => $archiveDeleted && ! $s3CleanupPending ? null : $backupLocation,
                'local_storage_deleted' => $archiveDeleted,
            ]);

            throw $exception;
        } finally {
            $this->execution->update(['finished_at' => now()]);
            BackupCreated::dispatch($team->id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $execution = $this->execution ?? $this->backup->executions()
            ->where('status', 'running')
            ->latest('id')
            ->first();

        if ($execution) {
            $recoveryError = $this->recoverIncompleteBackup($execution);
            $server = $this->backup->server();
            $filename = $execution->filename;
            $localStorageDeleted = $execution->local_storage_deleted;

            if ($server && filled($filename)) {
                try {
                    deleteBackupsLocally($filename, $server, throwError: true);
                    $localStorageDeleted = true;
                    if (! $execution->fresh()->s3_cleanup_pending) {
                        $filename = null;
                    }
                } catch (Throwable $cleanupException) {
                    $recoveryError .= ' Archive cleanup failed: '.$cleanupException->getMessage();
                }
            }

            $execution->update([
                'status' => 'failed',
                'message' => ($exception?->getMessage() ?? 'Volume backup timed out or was terminated.').$recoveryError,
                'finished_at' => now(),
                'filename' => $filename,
                'local_storage_deleted' => $localStorageDeleted,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function containersUsingVolume(string $source, Server $server): array
    {
        $output = instant_remote_process(
            ["containers=\$(docker ps -q) || exit 1; for container in \$containers; do paused=\$(docker inspect --format '{{.State.Paused}}' \"\$container\") || exit 1; [ \"\$paused\" = true ] && continue; mounts=\$(docker inspect --format '{{range .Mounts}}{{println .Source}}{{println .Name}}{{end}}' \"\$container\") || exit 1; if printf '%s\\n' \"\$mounts\" | grep -Fqx -- ".escapeshellarg($source).'; then echo "$container"; fi; done'],
            $server,
            disableMultiplexing: true,
        );
        $containers = collect(preg_split('/\s+/', trim((string) $output)))
            ->filter(fn (string $container): bool => preg_match('/^[a-f0-9]{6,64}$/i', $container) === 1)
            ->values()
            ->all();

        return $containers;
    }

    /**
     * @param  array<int, string>  $containers
     */
    private function archiveWithStoppedContainers(string $archiveCommand, array $containers, string $stateFile): string
    {
        if ($containers === []) {
            return $archiveCommand;
        }

        $containerList = implode(' ', $containers);
        $script = "set -eu\n"
            .'state_file='.escapeshellarg($stateFile)."\n"
            .": > \"\$state_file\"\n"
            ."cleanup() { status=\$?; trap - EXIT HUP INT TERM; while IFS= read -r container; do docker start \"\$container\" >/dev/null || status=1; done < \"\$state_file\"; [ \$status -eq 0 ] && rm -f \"\$state_file\"; exit \$status; }\n"
            ."trap cleanup EXIT\n"
            ."trap 'exit 129' HUP\n"
            ."trap 'exit 130' INT\n"
            ."trap 'exit 143' TERM\n"
            ."for container in {$containerList}; do echo \"\$container\" >> \"\$state_file\"; docker stop \"\$container\" >/dev/null; done\n"
            .$archiveCommand;

        return 'sh -c '.escapeshellarg($script);
    }

    private function recoverIncompleteBackup(ScheduledVolumeBackupExecution $execution): string
    {
        if (! $execution->stop_recovery_pending && ! $execution->s3_cleanup_pending) {
            return '';
        }

        try {
            VolumeBackupRecoveryJob::recover($execution);

            return '';
        } catch (Throwable $exception) {
            VolumeBackupRecoveryJob::dispatch($execution);

            return ' Container recovery failed: '.$exception->getMessage();
        }
    }

    private function uploadToS3(string $backupLocation, string $backupDirectory, Server $server): void
    {
        $s3 = $this->backup->s3;

        if (! $s3) {
            $this->backup->update(['save_s3' => false, 's3_storage_id' => null]);

            throw new \RuntimeException('The selected S3 storage no longer exists. S3 backup has been disabled.');
        }

        $s3->testConnection(shouldSave: true);
        $containerName = 'volume-upload-'.$this->execution->uuid;
        $image = coolifyHelperImage().':'.getHelperVersion();
        $resolveOptions = collect(SafeWebhookUrl::minioClientResolveOptions($s3->endpoint, $s3->trustedInternalHosts()))
            ->map(fn (string $option): string => '--resolve '.escapeshellarg($option))
            ->implode(' ');
        $resolveOptions = $resolveOptions === '' ? '' : ' '.$resolveOptions;

        try {
            instant_remote_process([
                'docker rm -f '.escapeshellarg($containerName).' >/dev/null 2>&1 || true',
                'docker run -d --name '.escapeshellarg($containerName).' --rm -v '
                    .escapeshellarg($backupLocation.':'.$backupLocation.':ro').' '.escapeshellarg($image),
                'docker exec '.escapeshellarg($containerName).' mc alias set'.$resolveOptions.' temporary '
                    .escapeshellarg($s3->endpoint).' '.escapeshellarg($s3->key).' '.escapeshellarg($s3->secret),
                'docker exec '.escapeshellarg($containerName).' mc cp '.escapeshellarg($backupLocation).' '
                    .escapeshellarg('temporary/'.$s3->bucket.$backupDirectory.'/'),
            ], $server, timeout: $this->timeout, disableMultiplexing: true);
        } finally {
            instant_remote_process(
                ['docker rm -f '.escapeshellarg($containerName)],
                $server,
                throwError: false,
                disableMultiplexing: true,
            );
        }
    }

    private function logCompressorInDevelopment(string $image, Server $server, int $compressionCpuPercentage): void
    {
        if (! isDev()) {
            return;
        }

        $script = "if command -v pigz >/dev/null 2>&1; then printf 'pigz -3 -p %s' \"\$(( (\$(nproc) * {$compressionCpuPercentage} + 99) / 100 ))\"; else printf 'gzip -3'; fi";
        $compressor = instant_remote_process(
            ['docker run --rm '.escapeshellarg($image).' sh -c '.escapeshellarg($script)],
            $server,
            timeout: 60,
            disableMultiplexing: true,
        );

        Log::info('Volume backup compressor selected', [
            'backup_id' => $this->backup->id,
            'execution_id' => $this->execution?->id,
            'compressor' => $compressor,
            'helper_image' => $image,
            'cpu_percentage' => $compressionCpuPercentage,
        ]);
    }

    private function compressionCpuPercentage(Server $server): int
    {
        $percentage = (int) ($server->settings->backup_compression_cpu_percentage ?? 25);

        return in_array($percentage, [25, 50, 75, 100], true) ? $percentage : 25;
    }

    private function removeExpiredBackups(Server $server): void
    {
        if ($this->hasRetentionLimits(
            $this->backup->retention_amount_locally,
            $this->backup->retention_days_locally,
            $this->backup->retention_max_storage_locally,
        )) {
            $localExecutions = $this->backup->executions()
                ->where('status', 'success')
                ->where('local_storage_deleted', false)
                ->get();
            $localExecutions = $this->executionsOutsideRetention(
                $localExecutions,
                $this->backup->retention_amount_locally,
                $this->backup->retention_days_locally,
                $this->backup->retention_max_storage_locally,
            );

            $filenames = $localExecutions->pluck('filename')->filter()->all();
            if ($filenames !== []) {
                deleteBackupsLocally($filenames, $server, throwError: true);
                $this->backup->executions()->whereKey($localExecutions->pluck('id')->all())
                    ->update(['local_storage_deleted' => true]);
            }
        }

        if ($this->backup->save_s3 && $this->backup->s3 && $this->hasRetentionLimits(
            $this->backup->retention_amount_s3,
            $this->backup->retention_days_s3,
            $this->backup->retention_max_storage_s3,
        )) {
            $s3Executions = $this->backup->executions()
                ->with('s3')
                ->where('status', 'success')
                ->where('s3_uploaded', true)
                ->where('s3_storage_deleted', false)
                ->get();
            $s3Executions = $this->executionsOutsideRetention(
                $s3Executions,
                $this->backup->retention_amount_s3,
                $this->backup->retention_days_s3,
                $this->backup->retention_max_storage_s3,
            );

            foreach ($s3Executions->groupBy('s3_storage_id') as $executions) {
                $s3 = $executions->first()->s3;
                if (! $s3) {
                    throw new \RuntimeException('The S3 storage used by an existing backup is unavailable.');
                }

                $filenames = $executions->pluck('filename')->filter()->all();
                if ($filenames !== []) {
                    deleteBackupsS3($filenames, $s3);
                    $this->backup->executions()->whereKey($executions->pluck('id')->all())
                        ->update(['s3_storage_deleted' => true]);
                }
            }
        }

        $this->backup->executions()
            ->where('local_storage_deleted', true)
            ->where(function (Builder $query): void {
                $query->where('s3_storage_deleted', true)->orWhereNull('s3_uploaded');
            })
            ->delete();
    }

    private function hasRetentionLimits(int $amount, int $days, float $maxStorageGb): bool
    {
        return $amount > 0 || $days > 0 || $maxStorageGb > 0;
    }

    private function executionsOutsideRetention(Collection $executions, int $amount, int $days, float $maxStorageGb): Collection
    {
        if ($amount === 0 && $days === 0 && $maxStorageGb == 0) {
            return collect();
        }

        $executionsToDelete = collect();

        if ($amount > 0) {
            $executionsToDelete = $executionsToDelete->merge($executions->skip($amount));
        }

        if ($days > 0) {
            $oldestAllowedDate = now()->subDays($days);
            $executionsToDelete = $executionsToDelete->merge(
                $executions->filter(fn (ScheduledVolumeBackupExecution $execution): bool => $execution->created_at->isBefore($oldestAllowedDate)),
            );
        }

        if ($maxStorageGb > 0) {
            $maxStorageBytes = $maxStorageGb * 1024 ** 3;
            $totalSize = 0;

            foreach ($executions as $index => $execution) {
                $totalSize += (int) $execution->size;

                if ($index > 0 && $totalSize > $maxStorageBytes) {
                    $executionsToDelete = $executionsToDelete->merge($executions->slice($index));

                    break;
                }
            }
        }

        return $executionsToDelete->unique('id')->values();
    }
}
