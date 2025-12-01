<?php

namespace App\Jobs;

use App\Events\BackupCreated;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Visus\Cuid2\Cuid2;

class PgbackrestBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public $timeout = 3600;

    public ?Team $team = null;

    public Server $server;

    public StandalonePostgresql $database;

    public ?ScheduledDatabaseBackupExecution $backup_log = null;

    public ?string $backup_log_uuid = null;

    private string $stanzaName;

    private string $containerName;

    public function __construct(public ScheduledDatabaseBackup $backup)
    {
        $this->onQueue('high');
        $this->timeout = $backup->timeout ?? 3600;
    }

    public function handle(): void
    {
        try {
            $this->team = Team::find($this->backup->team_id);
            if (! $this->team) {
                $this->backup->delete();

                return;
            }

            $this->database = $this->backup->database;
            if (! $this->database instanceof StandalonePostgresql) {
                throw new \Exception('pgBackRest backup only supports PostgreSQL databases');
            }

            $this->server = $this->database->destination->server;

            if (is_null($this->server)) {
                throw new \Exception('Server not found');
            }

            if (! $this->database->isPgbackrestEnabled()) {
                throw new \Exception('pgBackRest is not enabled for this database');
            }

            if ($this->backup->hasRunningExecution()) {
                throw new \Exception('A backup is already running for this schedule. Please wait for it to complete.');
            }

            $this->stanzaName = $this->database->getPgbackrestStanzaName();
            $this->containerName = $this->database->uuid;

            BackupCreated::dispatch($this->team->id);

            $status = str($this->database->status);
            if (! $status->startsWith('running')) {
                return;
            }

            $this->performBackup();

        } catch (\Throwable $e) {
            if ($this->backup_log) {
                $this->backup_log->update([
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            }
            $this->team?->notify(new BackupFailed($this->backup, $this->database, $e->getMessage(), $this->database->postgres_db));
            throw $e;
        } finally {
            if ($this->team) {
                BackupCreated::dispatch($this->team->id);
            }
        }
    }

    private function performBackup(): void
    {
        $attempts = 0;
        do {
            $this->backup_log_uuid = (string) new Cuid2;
            $exists = ScheduledDatabaseBackupExecution::where('uuid', $this->backup_log_uuid)->exists();
            $attempts++;
            if ($attempts >= 3 && $exists) {
                throw new \Exception('Unable to generate unique UUID for backup execution after 3 attempts');
            }
        } while ($exists);

        $backupType = $this->backup->pgbackrest_backup_type ?? 'full';

        $this->backup_log = ScheduledDatabaseBackupExecution::create([
            'uuid' => $this->backup_log_uuid,
            'database_name' => $this->database->postgres_db,
            'filename' => "pgbackrest:{$this->stanzaName}:{$backupType}",
            'scheduled_database_backup_id' => $this->backup->id,
            'status' => 'running',
            'local_storage_deleted' => false,
        ]);

        try {
            if (! $this->database->isPgbackrestEnabled()) {
                throw new \Exception('pgBackRest is not enabled for this database');
            }

            fixPgbackrestPermissions($this->database);
            $this->assertPostgresReadyForPgbackrest();
            $this->ensureStanzaExists();

            $output = $this->runPgbackrestBackupWithRetries($backupType);

            $lastBackup = getPgbackrestLatestBackup($this->database) ?? [];

            $this->backup_log->update([
                'status' => 'success',
                'message' => $output,
                'size' => $lastBackup['size'] ?? 0,
                'pgbackrest_label' => $lastBackup['label'] ?? null,
                'finished_at' => Carbon::now()->toImmutable(),
            ]);

            $this->team->notify(new BackupSuccess($this->backup, $this->database, $this->database->postgres_db));

        } catch (\Throwable $e) {
            $this->backup_log->update([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'finished_at' => Carbon::now()->toImmutable(),
            ]);
            throw $e;
        }
    }

    private function ensureStanzaExists(): void
    {
        $output = execPgbackrest($this->database, "--stanza={$this->stanzaName} info");

        if (str_contains($output, 'missing stanza')) {
            $createOut = execPgbackrest($this->database, "--stanza={$this->stanzaName} stanza-create");

            if (str_contains($createOut, 'ERROR') && ! str_contains($createOut, 'already exists')) {
                throw new \Exception("Failed to create pgBackRest stanza:\n{$createOut}");
            }
        }
    }

    private function assertPostgresReadyForPgbackrest(): void
    {
        $user = $this->database->postgres_user;
        $db = $this->database->postgres_db;

        $checkCommand = "docker exec {$this->containerName} psql -U {$user} -d {$db} -A -t -F '|' -c \"SELECT name, setting, pending_restart FROM pg_settings WHERE name IN ('archive_mode','wal_level');\"";

        $output = instant_remote_process([$checkCommand], $this->server, throwError: false);
        $output = trim($output ?? '');

        if ($output === '' || str_contains($output, 'psql:')) {
            throw new \Exception(
                "Unable to verify PostgreSQL configuration for pgBackRest. ".
                "Check that the container is running and 'psql' is available."
            );
        }

        $settings = [
            'archive_mode' => null,
            'wal_level' => null,
        ];
        $pendingRestart = [
            'archive_mode' => false,
            'wal_level' => false,
        ];

        foreach (preg_split('/\r\n|\r|\n/', $output) as $line) {
            if ($line === '') {
                continue;
            }
            [$name, $setting, $pending] = array_pad(explode('|', $line), 3, null);
            if (array_key_exists($name, $settings)) {
                $settings[$name] = $setting;
                $pendingRestart[$name] = ($pending === 't');
            }
        }
        $archiveOk = ($settings['archive_mode'] === 'on');
        $walLevelOk = in_array($settings['wal_level'], ['replica', 'logical'], true);

        if ($archiveOk && $walLevelOk) {
            return;
        }

        $msg = "PostgreSQL is not correctly configured for pgBackRest:\n";
        $msg .= "- archive_mode: got '{$settings['archive_mode']}', expected 'on'";
        if ($pendingRestart['archive_mode']) {
            $msg .= ' (change pending restart)';
        }
        $msg .= "\n- wal_level: got '{$settings['wal_level']}', expected 'replica' or 'logical'";
        if ($pendingRestart['wal_level']) {
            $msg .= ' (change pending restart)';
        }

        if ($pendingRestart['archive_mode'] || $pendingRestart['wal_level']) {
            $msg .= "\n\nPostgreSQL reports that configuration changes are pending restart. ";
            $msg .= 'This usually happens when pgBackRest was enabled on a running database. ';
            $msg .= "Please restart the database from the Coolify UI so that 'archive_mode=on' and 'wal_level=replica' take effect, then retry the backup.";
        } else {
            $msg .= "\n\nNo pending restart is reported. Please ensure that pgBackRest is enabled ";
            $msg .= "for this database and that the generated 'custom-postgres.conf' is being used. ";
            $msg .= 'After adjusting configuration, restart the database and retry the backup.';
        }

        throw new \Exception($msg);
    }

    private function runPgbackrestBackupWithRetries(string $backupType): string
    {
        $maxAttempts = 3;
        $baseDelaySeconds = 30;

        $lastOutput = '';
        $lastExitCode = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $backupCommand = "set +e; docker exec {$this->containerName} su postgres -c 'pgbackrest --stanza={$this->stanzaName} --type={$backupType} backup' 2>&1; EXIT_CODE=\$?; set -e; echo \"EXIT_CODE:\${EXIT_CODE}\"";

            $output = instant_remote_process([$backupCommand], $this->server, throwError: false);

            $exitCode = 0;
            if (preg_match('/EXIT_CODE:(\d+)$/', $output, $matches)) {
                $exitCode = (int) $matches[1];
                $output = preg_replace('/EXIT_CODE:\d+$/', '', $output);
            }

            $lastOutput = trim($output);
            $lastExitCode = $exitCode;

            if ($exitCode === 0) {
                return $lastOutput;
            }

            if ($this->isConfigurationError($exitCode, $lastOutput)) {
                $this->throwConfigurationError($exitCode, $lastOutput);
            }

            if ($attempt < $maxAttempts) {
                $delay = $baseDelaySeconds * $attempt;
                sleep($delay);
            }
        }

        $this->throwBackupFailure($lastExitCode, $lastOutput);
    }

    private function isConfigurationError(int $exitCode, string $output): bool
    {
        if ($exitCode === 87) {
            return true;
        }

        return str_contains($output, 'archive_mode must be enabled')
            || str_contains($output, 'wal_level must be at least replica');
    }

    private function throwConfigurationError(int $exitCode, string $output): void
    {
        $message = "pgBackRest backup failed with exit code {$exitCode} due to PostgreSQL configuration.\n\n";
        $message .= "pgBackRest reported that 'archive_mode' and/or 'wal_level' are not correctly set.\n";
        $message .= "This usually means the database was running before pgBackRest was enabled and has not been restarted.\n";
        $message .= "Please restart the database from the Coolify UI (so that 'archive_mode=on' and 'wal_level=replica' take effect) ";
        $message .= "and then retry the backup.\n\n";
        $message .= "--- Command Output ---\n{$output}\n";

        throw new \Exception($message);
    }

    private function throwBackupFailure(int $exitCode, string $output): void
    {
        $logCommand = "docker exec {$this->containerName} cat /var/lib/pgbackrest/log/{$this->stanzaName}-backup.log 2>/dev/null | tail -100";
        $logOutput = instant_remote_process([$logCommand], $this->server, throwError: false);

        $errorMessage = "pgBackRest backup failed with exit code {$exitCode} after multiple attempts.\n\n";
        $errorMessage .= "--- Command Output ---\n{$output}\n\n";
        if (! empty($logOutput)) {
            $errorMessage .= "--- pgBackRest Log ---\n{$logOutput}";
        }

        throw new \Exception($errorMessage);
    }
}
