<?php

namespace App\Jobs;

use App\Events\BackupCreated;
use App\Models\LocalPersistentVolume;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\VolumeBackupS3Replica;
use App\Notifications\VolumeBackup\BackupFailed;
use App\Notifications\VolumeBackup\BackupSuccess;
use App\Rules\SafeWebhookUrl;
use App\Services\ScheduledJobDeliveryService;
use App\Services\Security\SensitiveDataRedactor;
use App\Support\BackupCompression;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class VolumeBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 1;

    public int $timeout = ScheduledVolumeBackup::DEFAULT_TIMEOUT;

    private ?ScheduledVolumeBackupExecution $execution = null;

    public function __construct(public ScheduledVolumeBackup $backup, public ?string $occurrenceUuid = null)
    {
        $this->onQueue(crons_queue());
        $this->timeout = $backup->timeout ?? ScheduledVolumeBackup::DEFAULT_TIMEOUT;
    }

    public function middleware(): array
    {
        return [
            ScheduledJobDeliveryService::withoutOverlapping('volume-backup-'.$this->backup->id, $this->occurrenceUuid)
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
        if ($this->occurrenceUuid && ! app(ScheduledJobDeliveryService::class)->claim($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid)) {
            return;
        }

        $failed = false;
        $this->backup->loadMissing(['backupable.resource', 'team', 's3']);
        $server = $this->backup->server();
        $target = $this->backup->backupable;
        $team = $this->backup->team;

        if (! $server || ! $target || ! $team) {
            throw new \RuntimeException('The storage backup resource, team, or server no longer exists.');
        }

        $destinations = $this->backup->save_s3 ? $this->backup->selectedS3Storages() : collect();
        $this->execution = $this->backup->executions()->create([
            's3_storage_id' => $destinations->first()?->id,
        ]);
        foreach ($destinations as $s3) {
            $this->execution->s3Replicas()->create(['s3_storage_id' => $s3->id]);
        }
        BackupCreated::dispatch($team->id);

        $backupDirectory = backup_dir().'/volumes/'.str($team->name)->slug().'-'.$team->id.'/'.$target->uuid;
        $filename = str($this->backup->targetType())->lower().'-'.str($this->backup->targetName())->slug().'-'.Carbon::now()->timestamp.'.tar.gz';
        $backupLocation = $backupDirectory.'/'.$filename;
        $this->execution->update(['filename' => $backupLocation]);
        // Streaming writes no local archive, so it can upload to one destination only.
        $streamToS3 = $this->backup->disable_local_backup && $destinations->count() === 1;

        try {
            $source = $this->backup->sourcePath();
            $containerName = 'volume-backup-'.$this->execution->uuid;
            $image = coolifyHelperImage().':'.getHelperVersion();
            $compressionCpuPercentage = BackupCompression::cpuPercentage($server->settings->backup_compression_cpu_percentage);
            $this->logCompressorInDevelopment($image, $server, $compressionCpuPercentage);
            $verifySourceCommand = $target instanceof LocalPersistentVolume && blank($target->host_path)
                ? 'docker volume inspect '.escapeshellarg($source).' >/dev/null'
                : 'test -d '.escapeshellarg($source);

            $archiveScript = self::archiveScript($compressionCpuPercentage);
            if ($streamToS3) {
                $this->execution->update(['local_storage_deleted' => true]);
                $archiveCommand = $this->streamToS3Command($destinations->first(), $archiveScript, $backupLocation, $source, $containerName, $image);
                $this->execution->update(['s3_cleanup_pending' => true]);
            } else {
                $archiveCommand = 'docker run --rm --name '.escapeshellarg($containerName)
                    .' -v '.escapeshellarg($source.':/volume:ro')
                    .' '.escapeshellarg($image)
                    .' sh -c '.escapeshellarg($archiveScript)
                    .' > '.escapeshellarg($backupLocation);
            }

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

            $archiveOutput = instant_remote_process(array_filter([
                $verifySourceCommand,
                $streamToS3 ? null : 'mkdir -p '.escapeshellarg($backupDirectory),
                $archiveCommand,
            ]), $server, timeout: $this->timeout, disableMultiplexing: true);
            $this->execution->update([
                'stop_container_ids' => null,
                'stop_recovery_pending' => false,
            ]);

            $size = $streamToS3
                ? (int) str($archiveOutput)->trim()->afterLast("\n")->toString()
                : (int) instant_remote_process(
                    ['du -b '.escapeshellarg($backupLocation).' | cut -f1'],
                    $server,
                    disableMultiplexing: true,
                );

            if ($size <= 0) {
                throw new \RuntimeException('The storage backup archive is empty or was not created.');
            }

            $warning = null;
            $s3Uploaded = false;
            $s3CleanupPending = false;
            $localStorageDeleted = $streamToS3;

            if ($streamToS3) {
                $s3Uploaded = true;
                $this->execution->s3Replicas()->update(['s3_uploaded' => true]);
                $this->execution->refreshS3Summary();
                $this->execution->update(['s3_cleanup_pending' => false]);
            } elseif ($this->backup->save_s3 && $destinations->isEmpty()) {
                $warning = 'S3 upload failed: The selected S3 storage no longer exists.';
            } elseif ($this->backup->save_s3) {
                $s3CleanupPending = true;
                $this->execution->update(['s3_cleanup_pending' => true]);
                $uploadErrors = $this->uploadToDestinations($backupLocation, $backupDirectory, $server);
                $s3Uploaded = $uploadErrors === [];

                if ($s3Uploaded) {
                    $s3CleanupPending = false;
                } else {
                    $warning = 'S3 upload failed: '.implode(' ', $uploadErrors);

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

            $warning = $warning === null ? null : $this->redact($warning);
            $this->execution->update([
                'status' => 'success',
                'message' => $warning,
                'size' => $size,
                'filename' => $backupLocation,
                's3_cleanup_pending' => $s3CleanupPending,
                'local_storage_deleted' => $localStorageDeleted,
            ]);

            try {
                $this->removeExpiredBackups($server);
            } catch (Throwable $exception) {
                Log::channel('scheduled-errors')->warning('Volume backup retention cleanup failed', [
                    'backup_id' => $this->backup->id,
                    'execution_id' => $this->execution->id,
                    'error' => $this->redact($exception->getMessage()),
                ]);
            }
        } catch (Throwable $exception) {
            $failed = true;
            $recoveryError = $this->recoverIncompleteBackup($this->execution);
            $archiveDeleted = $streamToS3;

            if ($streamToS3) {
                $exception = new \RuntimeException(
                    'S3-only streaming backup failed: '.$exception->getMessage()
                    .'. The S3 destination may not support streaming uploads. Enable local backups to use the local archive upload method.',
                    previous: $exception,
                );
            }

            if (! $streamToS3) {
                try {
                    deleteBackupsLocally($backupLocation, $server, throwError: true);
                    $archiveDeleted = true;
                } catch (Throwable $cleanupException) {
                    $recoveryError .= ' Archive cleanup failed: '.$cleanupException->getMessage();
                }
            }

            $this->execution->s3Replicas()->whereNull('s3_uploaded')->update(['s3_uploaded' => false]);
            $this->execution->refreshS3Summary();
            $s3CleanupPending = $this->execution->fresh()->s3_cleanup_pending;

            $this->execution->update([
                'status' => 'failed',
                'message' => $this->redact($exception->getMessage().$recoveryError),
                'filename' => $streamToS3
                    ? ($s3CleanupPending ? $backupLocation : null)
                    : ($archiveDeleted && ! $s3CleanupPending ? null : $backupLocation),
                'local_storage_deleted' => $archiveDeleted,
            ]);

            $safeMessage = $this->redact($exception->getMessage());
            throw $safeMessage === $exception->getMessage()
                ? $exception
                : new \RuntimeException($safeMessage, (int) $exception->getCode());
        } finally {
            if (! $failed && $this->occurrenceUuid) {
                app(ScheduledJobDeliveryService::class)->complete($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid);
            }

            $this->execution->update(['finished_at' => now()]);
            BackupCreated::dispatch($team->id);
        }

        $team->notify(new BackupSuccess($this->backup, $warning));
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->occurrenceUuid) {
            app(ScheduledJobDeliveryService::class)->fail($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid);
        }

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
                'message' => $this->redact(($exception?->getMessage() ?? 'Volume backup timed out or was terminated.').$recoveryError),
                'finished_at' => now(),
                'filename' => $filename,
                'local_storage_deleted' => $localStorageDeleted,
            ]);
        }

        $this->backup->team?->notify(new BackupFailed(
            $this->backup,
            $this->redact($exception?->getMessage() ?? 'Volume backup timed out or was terminated.'),
        ));
    }

    /**
     * GNU tar exits with 1 when a file changes while it is read (for example, an app that writes to the volume
     * during a live backup). The archive is still valid, so only exit codes above 1 fail the backup.
     */
    public static function archiveScript(int $compressionCpuPercentage): string
    {
        $compressorCommand = BackupCompression::compressorCommand($compressionCpuPercentage);

        return "compressor=\$({$compressorCommand}); { tar -I \"\$compressor\" -cf - -C /volume . || { status=\$?; [ \$status -eq 1 ] || exit \$status; }; }";
    }

    /**
     * Redact the key and secret of every S3 destination of the backup from text that is stored or sent in notifications.
     */
    private function redact(string $text): string
    {
        try {
            $s3 = $this->backup->s3;
            $lockedSecrets = [$s3?->key, $s3?->secret];
            foreach ($this->backup->selectedS3Storages() as $storage) {
                $lockedSecrets[] = $storage->key;
                $lockedSecrets[] = $storage->secret;
            }
        } catch (Throwable) {
            return SensitiveDataRedactor::FAILURE_MESSAGE;
        }

        return app(SensitiveDataRedactor::class)->redactText($text, lockedSecrets: array_filter($lockedSecrets, fn ($secret): bool => is_string($secret) && $secret !== ''));
    }

    /**
     * Containers can exit and be removed (e.g. `--rm` backup helpers) between `docker ps` and
     * `docker inspect`. Skip those, but still fail when an existing container cannot be inspected.
     *
     * @return array<int, string>
     */
    private function containersUsingVolume(string $source, Server $server): array
    {
        $output = instant_remote_process(
            ["containers=\$(docker ps -q) || exit 1; for container in \$containers; do mounts=\$(docker inspect --format '{{if not .State.Paused}}{{range .Mounts}}{{println .Source}}{{println .Name}}{{end}}{{end}}' \"\$container\" 2>/dev/null) || { remaining=\$(docker ps -aq --filter \"id=\$container\") || exit 1; [ -z \"\$remaining\" ] && continue; docker inspect \"\$container\" >/dev/null; exit 1; }; if printf '%s\\n' \"\$mounts\" | grep -Fqx -- ".escapeshellarg($source).'; then echo "$container"; fi; done'],
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

    /**
     * Uploads the local archive to every destination of the execution and records the result on each copy.
     *
     * @return array<int, string> One error message per failed destination.
     */
    private function uploadToDestinations(string $backupLocation, string $backupDirectory, Server $server): array
    {
        $errors = [];

        foreach ($this->execution->s3Replicas()->with('s3')->get() as $replica) {
            /** @var VolumeBackupS3Replica $replica */
            try {
                if (! $replica->s3) {
                    throw new \RuntimeException('The selected S3 storage no longer exists.');
                }

                $this->uploadToS3($replica->s3, $backupLocation, $backupDirectory, $server);
                $replica->update(['s3_uploaded' => true, 'message' => null]);
            } catch (Throwable $exception) {
                $message = $this->redact($exception->getMessage());
                $replica->update(['s3_uploaded' => false, 'message' => $message]);
                $errors[] = ($replica->s3?->name ?? 'Deleted storage').': '.$message;
            }
        }

        $this->execution->refreshS3Summary();

        return $errors;
    }

    private function uploadToS3(S3Storage $s3, string $backupLocation, string $backupDirectory, Server $server): void
    {
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
                'docker run -d --name '.escapeshellarg($containerName).' --rm '.$s3->minioClientRegionOption().' -v '
                    .escapeshellarg($backupLocation.':'.$backupLocation.':ro').' '.escapeshellarg($image),
                'docker exec '.escapeshellarg($containerName).' mc alias set'.$resolveOptions.' temporary '
                    .escapeshellarg($s3->endpoint).' '.escapeshellarg($s3->key).' '.escapeshellarg($s3->secret),
                'docker exec '.escapeshellarg($containerName).' mc cp'.$resolveOptions.' '.escapeshellarg($backupLocation).' '
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

    private function streamToS3Command(S3Storage $s3, string $archiveScript, string $backupLocation, string $source, string $containerName, string $image): string
    {
        $s3->testConnection(shouldSave: true);
        $resolveOptions = collect(SafeWebhookUrl::minioClientResolveOptions($s3->endpoint, $s3->trustedInternalHosts()))
            ->map(fn (string $option): string => '--resolve '.escapeshellarg($option))
            ->implode(' ');
        $resolveOptions = $resolveOptions === '' ? '' : ' '.$resolveOptions;
        $destination = 'temporary/'.$s3->bucket.$backupLocation;
        // `mc pipe --quiet` panics in recent mc releases, so keep its progress bar out of the error output instead.
        // Each progress frame starts with "\r", so printing the last field per line keeps only the final frame and real errors.
        $mcPipeLog = '/tmp/mc-pipe.log';
        // Object Lock buckets need Content-MD5 on each upload. `mc cp` adds it when the bucket lock is enabled, `mc pipe` only with `--checksum=MD5`.
        $streamScript = 'set -o pipefail; mc alias set'.$resolveOptions.' temporary '
            .escapeshellarg($s3->endpoint).' '.escapeshellarg($s3->key).' '.escapeshellarg($s3->secret)
            .' >/dev/null && lock=$(mc retention info --default --json'.$resolveOptions.' '.escapeshellarg('temporary/'.$s3->bucket).' 2>/dev/null || true)'
            .' && case "$lock" in *\'"enabled":"Enabled"\'*) checksum=--checksum=MD5 ;; *) checksum= ;; esac'
            .' && ('.$archiveScript.' | mc pipe'.$resolveOptions.' $checksum '.escapeshellarg($destination).' >/dev/null 2>'.$mcPipeLog
            .' || { status=$?; awk -F '.escapeshellarg('\r').' '.escapeshellarg('{ print $NF }').' '.$mcPipeLog.' >&2; exit $status; })'
            .' && mc stat --json'.$resolveOptions.' '.escapeshellarg($destination)
            .' | sed -n '.escapeshellarg('s/.*"size":\([0-9][0-9]*\).*/\1/p');

        return 'docker run --rm --name '.escapeshellarg($containerName)
            .' '.$s3->minioClientRegionOption()
            .' -v '.escapeshellarg($source.':/volume:ro')
            .' '.escapeshellarg($image)
            .' sh -c '.escapeshellarg($streamScript);
    }

    private function logCompressorInDevelopment(string $image, Server $server, int $compressionCpuPercentage): void
    {
        if (! isDev()) {
            return;
        }

        $script = BackupCompression::compressorCommand($compressionCpuPercentage);
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

        if ($this->backup->save_s3 && $this->hasRetentionLimits(
            $this->backup->retention_amount_s3,
            $this->backup->retention_days_s3,
            $this->backup->retention_max_storage_s3,
        )) {
            $liveCopies = fn ($query) => $query->where('s3_uploaded', true)->where('s3_storage_deleted', false);
            $s3Executions = $this->backup->executions()
                ->with(['s3Replicas' => fn ($query) => $liveCopies($query)->with('s3')])
                ->where('status', 'success')
                ->whereHas('s3Replicas', $liveCopies)
                ->get();

            // Each destination keeps its own copies within the same retention settings.
            foreach ($s3Executions->flatMap->s3Replicas->pluck('s3_storage_id')->unique() as $storageId) {
                $executions = $this->executionsOutsideRetention(
                    $s3Executions->filter(fn (ScheduledVolumeBackupExecution $execution): bool => $execution->s3Replicas->contains('s3_storage_id', $storageId))->values(),
                    $this->backup->retention_amount_s3,
                    $this->backup->retention_days_s3,
                    $this->backup->retention_max_storage_s3,
                );
                if ($executions->isEmpty()) {
                    continue;
                }

                $replicas = $executions->map(fn (ScheduledVolumeBackupExecution $execution): VolumeBackupS3Replica => $execution->s3Replicas->firstWhere('s3_storage_id', $storageId));
                $s3 = $replicas->first()->s3;
                if (! $s3) {
                    VolumeBackupS3Replica::query()->whereKey($replicas->pluck('id')->all())->update(['s3_storage_deleted' => true]);
                    $executions->each(fn (ScheduledVolumeBackupExecution $execution) => $execution->refreshS3Summary());

                    continue;
                }

                $filenames = $executions->pluck('filename')->filter()->all();
                if ($filenames !== []) {
                    deleteBackupsS3($filenames, $s3);
                }
                VolumeBackupS3Replica::query()->whereKey($replicas->pluck('id')->all())->update(['s3_storage_deleted' => true]);
                $executions->each(fn (ScheduledVolumeBackupExecution $execution) => $execution->refreshS3Summary());
            }
        }

        $this->backup->executions()
            ->where('local_storage_deleted', true)
            ->where('stop_recovery_pending', false)
            ->where('s3_cleanup_pending', false)
            ->withoutLiveS3Copies()
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
