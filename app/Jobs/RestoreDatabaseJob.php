<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Notifications\Database\RestoreFailed;
use App\Notifications\Database\RestoreSuccess;
use App\Services\PgBackRestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RestoreDatabaseJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public $timeout = 7200; // 2 hours for large database restores

    public function __construct(
        public StandalonePostgresql $database,
        public ScheduledDatabaseBackup $backupConfig,
        public ?string $backupLabel = null,
        public ?string $targetTime = null,
        public bool $delta = false,
        public string $jobId = ''
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            Log::info('Starting database restore', [
                'database' => $this->database->uuid,
                'backup_label' => $this->backupLabel,
                'target_time' => $this->targetTime,
                'delta' => $this->delta,
                'job_id' => $this->jobId,
            ]);

            $server = $this->database->destination?->server;
            $team = $this->database->team();

            if (! $server) {
                throw new \Exception('Server not found for database');
            }

            if (! $team) {
                throw new \Exception('Team not found for database');
            }

            // Validate backup method
            if ($this->backupConfig->backup_method !== 'pgbackrest') {
                throw new \Exception('Restore is only supported for pgBackRest backups');
            }

            // Create pgBackRest service
            $pgBackRestService = new PgBackRestService(
                $this->database,
                $this->backupConfig,
                $server
            );

            // Step 1: Stop PostgreSQL container
            Log::info('Stopping PostgreSQL container', ['database' => $this->database->uuid]);
            $this->stopPostgresContainer($server);

            // Step 2: Perform restore
            Log::info('Performing pgBackRest restore', [
                'database' => $this->database->uuid,
                'backup_label' => $this->backupLabel,
                'target_time' => $this->targetTime,
            ]);

            $pgBackRestService->restore(
                backupLabel: $this->backupLabel,
                targetTime: $this->targetTime,
                delta: $this->delta
            );

            // Step 3: Start PostgreSQL container
            Log::info('Starting PostgreSQL container', ['database' => $this->database->uuid]);
            $this->startPostgresContainer($server);

            // Step 4: Wait for PostgreSQL to be ready
            Log::info('Waiting for PostgreSQL to be ready', ['database' => $this->database->uuid]);
            $this->waitForPostgres($server);

            Log::info('Database restore completed successfully', [
                'database' => $this->database->uuid,
                'job_id' => $this->jobId,
            ]);

            // Send success notification
            if ($team) {
                $team->notify(new RestoreSuccess(
                    $this->database,
                    $this->backupLabel,
                    $this->targetTime
                ));
            }

        } catch (\Throwable $e) {
            Log::error('Database restore failed', [
                'database' => $this->database->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'job_id' => $this->jobId,
            ]);

            // Try to restart the database even if restore failed
            try {
                $server = $this->database->destination?->server;
                if ($server) {
                    $this->startPostgresContainer($server);
                }
            } catch (\Throwable $startError) {
                Log::error('Failed to restart database after restore failure', [
                    'database' => $this->database->uuid,
                    'error' => $startError->getMessage(),
                ]);
            }

            // Send failure notification
            $team = $this->database->team();
            if ($team) {
                $team->notify(new RestoreFailed(
                    $this->database,
                    $e->getMessage()
                ));
            }

            throw $e;
        }
    }

    private function stopPostgresContainer(Server $server): void
    {
        $containerName = $this->database->uuid;
        // Stop PostgreSQL process inside the running container
        $command = "docker exec -u postgres {$containerName} pg_ctl -D /var/lib/postgresql/data -m fast stop";
        instant_remote_process([$command], $server);

        // Wait for PostgreSQL to stop
        sleep(5);
    }

    private function startPostgresContainer(Server $server): void
    {
        $containerName = $this->database->uuid;
        // Start PostgreSQL process inside the running container
        $command = "docker exec -u postgres {$containerName} pg_ctl -D /var/lib/postgresql/data -w start";
        instant_remote_process([$command], $server);

        // Wait for PostgreSQL to start
        sleep(5);
    }

    private function waitForPostgres(Server $server, int $maxAttempts = 30): void
    {
        $containerName = $this->database->uuid;
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            try {
                $command = "docker exec {$containerName} pg_isready -U {$this->database->postgres_user}";
                $result = instant_remote_process([$command], $server, false);

                if (str_contains($result, 'accepting connections')) {
                    Log::info('PostgreSQL is ready', [
                        'database' => $this->database->uuid,
                        'attempts' => $attempts + 1,
                    ]);

                    return;
                }
            } catch (\Throwable $e) {
                // Continue waiting
            }

            $attempts++;
            sleep(2);
        }

        throw new \Exception('PostgreSQL did not become ready within the timeout period');
    }
}
