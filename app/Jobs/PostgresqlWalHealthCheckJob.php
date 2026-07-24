<?php

namespace App\Jobs;

use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PostgresqlWalBackupExecution;
use App\Notifications\Database\PostgresqlWalArchivingFailed;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class PostgresqlWalHealthCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    private ?PostgresqlWalBackupExecution $execution = null;

    public function __construct(public PostgresqlWalBackupConfiguration $configuration)
    {
        $this->onQueue(crons_queue());
    }

    public function handle(): void
    {
        $this->configuration->refresh()->loadMissing([
            'database.destination.server.settings',
            'database.environment.project',
            's3',
            'team',
        ]);

        if ($this->configuration->status === 'disabled') {
            return;
        }

        $this->execution = $this->configuration->executions()->create([
            'operation' => 'health_check',
        ]);

        try {
            $database = $this->configuration->database;
            $server = $database?->destination?->server;

            if (! $database || ! $server) {
                throw new RuntimeException('The PostgreSQL database or server for this WAL-G health check is unavailable.');
            }
            if (! str((string) $database->status)->startsWith('running')) {
                throw new RuntimeException('The PostgreSQL database is not running.');
            }

            $archiveState = $this->readArchiveState();

            if (! $this->configuration->enabled && $archiveState['archive_mode'] === 'off') {
                $this->configuration->update([
                    'status' => 'disabled',
                    'last_health_message' => 'WAL archiving is physically disabled.',
                    'last_failed_count' => $archiveState['failed_count'],
                    'last_archived_wal' => $archiveState['last_archived_wal'],
                    'last_archived_at' => $archiveState['last_archived_at'],
                    'last_failed_wal' => $archiveState['last_failed_wal'],
                    'last_failed_at' => $archiveState['last_failed_at'],
                ]);
                $this->completeExecution('success', 'WAL archiving is physically disabled.');

                return;
            }

            if (! $this->configuration->s3?->isUsable()) {
                $this->failHealthCheck('The WAL-G S3 storage is detached or unusable. PostgreSQL may retain WAL until the data disk fills.');

                return;
            }

            if ($archiveState['archive_mode'] !== 'on' || ! str($archiveState['archive_command'])->contains('coolify-walg-archive')) {
                if ($this->configuration->status === 'pending_restart') {
                    $message = 'WAL-G archive settings are waiting for a database restart.';
                    $this->configuration->update([
                        'last_health_message' => $message,
                        'last_failed_count' => $archiveState['failed_count'],
                    ]);
                    $this->completeExecution('success', $message);

                    return;
                }

                $this->failHealthCheck('PostgreSQL WAL archiving is not using the Coolify WAL-G archive command.');

                return;
            }

            $storedFailedCount = $this->configuration->last_failed_count;
            $failedCountIncreased = $archiveState['failed_count'] > $storedFailedCount;
            $counterWasReset = $archiveState['failed_count'] < $storedFailedCount;

            $this->configuration->update([
                'last_failed_count' => $archiveState['failed_count'],
                'last_archived_wal' => $archiveState['last_archived_wal'],
                'last_archived_at' => $archiveState['last_archived_at'],
                'last_failed_wal' => $archiveState['last_failed_wal'],
                'last_failed_at' => $archiveState['last_failed_at'],
            ]);

            if ($failedCountIncreased) {
                $this->failHealthCheck('PostgreSQL reported a new WAL archive failure. WAL may accumulate until the data disk fills.');

                return;
            }

            $baseBackupWarning = $this->baseBackupWarning();
            $status = $baseBackupWarning === null ? 'healthy' : 'warning';
            $message = $baseBackupWarning
                ?? ($counterWasReset
                    ? 'WAL archiving is healthy; the PostgreSQL archiver counters were re-baselined after a reset.'
                    : 'WAL archiving is healthy.');

            $this->configuration->update([
                'status' => $status,
                'last_health_message' => $message,
            ]);
            $this->completeExecution('success', $message);
        } catch (Throwable $exception) {
            $this->failHealthCheck($exception->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->failHealthCheck($exception?->getMessage() ?? 'The WAL-G health check job failed.');
    }

    /**
     * @return array{
     *     archive_mode: string,
     *     archive_command: string,
     *     archived_count: int,
     *     failed_count: int,
     *     last_archived_wal: ?string,
     *     last_archived_at: ?Carbon,
     *     last_failed_wal: ?string,
     *     last_failed_at: ?Carbon
     * }
     */
    private function readArchiveState(): array
    {
        $database = $this->configuration->database;
        $server = $database->destination->server;
        $query = <<<'SQL'
SELECT current_setting('archive_mode'), current_setting('archive_command'), archived_count, failed_count, COALESCE(last_archived_wal, ''), COALESCE(last_archived_time::text, ''), COALESCE(last_failed_wal, ''), COALESCE(last_failed_time::text, '') FROM pg_stat_archiver;
SQL;
        $command = 'docker exec '.escapeshellarg($database->uuid)
            .' psql --username '.escapeshellarg($database->postgres_user)
            .' --dbname '.escapeshellarg($database->postgres_db)
            .' --tuples-only --no-align --field-separator='.escapeshellarg('|')
            .' --command '.escapeshellarg($query);
        $output = trim((string) instant_remote_process([$command], $server, timeout: $this->timeout));
        $fields = explode('|', $output);

        if (count($fields) !== 8) {
            throw new RuntimeException('PostgreSQL returned an invalid WAL archiver health response.');
        }

        return [
            'archive_mode' => $fields[0],
            'archive_command' => $fields[1],
            'archived_count' => (int) $fields[2],
            'failed_count' => (int) $fields[3],
            'last_archived_wal' => filled($fields[4]) ? $fields[4] : null,
            'last_archived_at' => filled($fields[5]) ? Carbon::parse($fields[5]) : null,
            'last_failed_wal' => filled($fields[6]) ? $fields[6] : null,
            'last_failed_at' => filled($fields[7]) ? Carbon::parse($fields[7]) : null,
        ];
    }

    private function baseBackupWarning(): ?string
    {
        $lastSuccessfulBackup = $this->configuration->last_successful_base_backup_at;
        if (! $lastSuccessfulBackup) {
            return 'WAL archiving is healthy, but no successful WAL-G base backup exists yet.';
        }

        try {
            $serverTimezone = data_get(
                $this->configuration,
                'database.destination.server.settings.server_timezone',
                config('app.timezone'),
            );
            if (! validate_timezone($serverTimezone)) {
                $serverTimezone = config('app.timezone');
            }
            $cron = new CronExpression(VALID_CRON_STRINGS[$this->configuration->base_backup_frequency] ?? $this->configuration->base_backup_frequency);
            $nextDue = Carbon::instance($cron->getNextRunDate($lastSuccessfulBackup->copy()->setTimezone($serverTimezone)));
            $overdueAfter = $nextDue->addSeconds($this->configuration->timeout + 300);

            if (now()->setTimezone($serverTimezone)->isAfter($overdueAfter)) {
                return 'WAL archiving is healthy, but the scheduled WAL-G base backup is overdue.';
            }
        } catch (Throwable) {
            return 'WAL archiving is healthy, but the WAL-G base backup schedule is invalid.';
        }

        return null;
    }

    private function failHealthCheck(string $message): void
    {
        $message = str($message)->limit(10000)->toString();
        $shouldNotify = $this->configuration->last_health_message !== $message;

        if ($this->configuration->exists) {
            $this->configuration->update([
                'status' => 'failed',
                'last_health_message' => $message,
            ]);
        }
        $this->completeExecution('failed', $message);

        if ($shouldNotify && $this->configuration->team && $this->configuration->database) {
            $this->configuration->team->notify(new PostgresqlWalArchivingFailed(
                $this->configuration->database,
                $message,
            ));
        }
    }

    private function completeExecution(string $status, string $message): void
    {
        $this->execution?->refresh();
        if ($this->execution?->status !== 'running') {
            return;
        }

        $this->execution->update([
            'status' => $status,
            'message' => $message,
            'finished_at' => now(),
        ]);
    }
}
