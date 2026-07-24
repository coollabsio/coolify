<?php

namespace App\Jobs;

use App\Actions\Database\ResolvePostgresqlDataDirectory;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PostgresqlWalBackupExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PostgresqlWalBaseBackupJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 1;

    public int $timeout;

    public int $uniqueFor;

    private ?PostgresqlWalBackupExecution $execution = null;

    public function __construct(public PostgresqlWalBackupConfiguration $configuration)
    {
        $this->onQueue(crons_queue());
        $this->timeout = $configuration->timeout;
        $this->uniqueFor = $configuration->timeout + 300;
    }

    public static function repositoryLockKey(int $configurationId): string
    {
        return 'postgresql-wal-repo-'.$configurationId;
    }

    public function uniqueId(): string
    {
        return (string) $this->configuration->id;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::repositoryLockKey($this->configuration->id)))
                ->shared()
                ->expireAfter($this->timeout + 300)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $this->configuration->refresh()->loadMissing(['database.destination.server', 's3']);

        if (! $this->configuration->enabled || ! in_array($this->configuration->status, ['healthy', 'warning'], true)) {
            return;
        }

        $this->execution = $this->configuration->executions()->create([
            'operation' => 'base_backup',
        ]);
        $this->configuration->update(['last_base_backup_at' => now()]);

        try {
            $database = $this->configuration->database;
            $server = $database?->destination?->server;

            if (! $database || ! $server) {
                throw new RuntimeException('The PostgreSQL database or server for this WAL-G backup is unavailable.');
            }
            if (! $this->configuration->s3?->isUsable()) {
                throw new RuntimeException('The S3 storage for this WAL-G backup is unavailable or unusable.');
            }
            if (! str((string) $database->status)->startsWith('running')) {
                throw new RuntimeException('The PostgreSQL database must be running before a WAL-G base backup can start.');
            }

            $dataDirectory = ResolvePostgresqlDataDirectory::run($database);
            $command = 'docker exec '.escapeshellarg($database->uuid)
                .' sh -c '.escapeshellarg('set -a; . /etc/wal-g/env; exec wal-g backup-push "$1"')
                .' sh '.escapeshellarg($dataDirectory);
            $output = (string) instant_remote_process(
                [$command],
                $server,
                timeout: $this->timeout,
                disableMultiplexing: true,
            );
            $backupName = $this->backupNameFromOutput($output);

            $this->execution->update([
                'status' => 'success',
                'message' => str($output)->limit(10000)->toString(),
                'backup_name' => $backupName,
                'finished_at' => now(),
            ]);
            $this->configuration->update([
                'status' => 'healthy',
                'last_health_message' => 'The latest WAL-G base backup completed successfully.',
                'last_successful_base_backup_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($exception);

            throw $exception;
        }

        try {
            (new PostgresqlWalRetentionJob($this->configuration->fresh()))->handle();
        } catch (Throwable $exception) {
            Log::channel('scheduled-errors')->warning('PostgreSQL WAL-G retention failed after a successful base backup', [
                'configuration_id' => $this->configuration->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception ?? new RuntimeException('The WAL-G base backup job failed.'));
    }

    private function backupNameFromOutput(string $output): ?string
    {
        if (preg_match('/\b(base_[A-Za-z0-9_]+)\b/', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function markFailed(Throwable $exception): void
    {
        $this->execution?->refresh();
        if ($this->execution?->status === 'running') {
            $this->execution->update([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        }

        if ($this->configuration->exists) {
            $this->configuration->update([
                'status' => 'failed',
                'last_health_message' => 'WAL-G base backup failed: '.$exception->getMessage(),
            ]);
        }
    }
}
