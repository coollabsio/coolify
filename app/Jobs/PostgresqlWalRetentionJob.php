<?php

namespace App\Jobs;

use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PostgresqlWalBackupExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class PostgresqlWalRetentionJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 1;

    public int $timeout;

    private ?PostgresqlWalBackupExecution $execution = null;

    public function __construct(public PostgresqlWalBackupConfiguration $configuration)
    {
        $this->onQueue(crons_queue());
        $this->timeout = $configuration->timeout;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(PostgresqlWalBaseBackupJob::repositoryLockKey($this->configuration->id)))
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
            'operation' => 'retention',
        ]);

        try {
            $database = $this->configuration->database;
            $server = $database?->destination?->server;

            if (! $database || ! $server) {
                throw new RuntimeException('The PostgreSQL database or server for WAL-G retention is unavailable.');
            }
            if (! $this->configuration->s3?->isUsable()) {
                throw new RuntimeException('The S3 storage for WAL-G retention is unavailable or unusable.');
            }

            $retentionCount = $this->configuration->retention_full_backups;
            $command = 'docker exec '.escapeshellarg($database->uuid)
                .' sh -c '.escapeshellarg('set -a; . /etc/wal-g/env; exec wal-g delete retain FULL "$1" --use-sentinel-time --confirm')
                .' sh '.escapeshellarg((string) $retentionCount);
            $output = (string) instant_remote_process(
                [$command],
                $server,
                timeout: $this->timeout,
                disableMultiplexing: true,
            );

            $this->execution->update([
                'status' => 'success',
                'message' => str($output)->limit(10000)->toString(),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception ?? new RuntimeException('The WAL-G retention job failed.'));
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
                'last_health_message' => 'WAL-G retention failed: '.$exception->getMessage(),
            ]);
        }
    }
}
