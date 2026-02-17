<?php

namespace App\Jobs;

use App\Events\BackupCreated;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PgBackRestRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public ?Team $team = null;

    public Server $server;

    public StandalonePostgresql|ServiceDatabase $database;

    public ?string $container_name = null;

    public ?string $restore_output = null;

    public ?string $error_output = null;

    public $timeout = 7200;

    public function __construct(
        public ScheduledDatabaseBackup $backup,
        public ?string $backupLabel = null,
    ) {
        $this->onQueue('high');
        $this->timeout = $backup->timeout ?? 7200;
    }

    public function handle(): void
    {
        try {
            $this->team = Team::find($this->backup->team_id);
            if (! $this->team) {
                return;
            }

            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $this->database = data_get($this->backup, 'database');
                $this->server = $this->database->service->server;
            } else {
                $this->database = data_get($this->backup, 'database');
                $this->server = $this->database->destination->server;
            }

            if (is_null($this->server) || is_null($this->database)) {
                throw new \Exception('Server or database not found.');
            }

            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $serviceUuid = $this->database->service->uuid;
                $this->container_name = "{$this->database->name}-$serviceUuid";
            } else {
                $this->container_name = $this->database->uuid;
            }

            $pgUser = 'postgres';
            if ($this->database instanceof StandalonePostgresql) {
                $pgUser = $this->database->postgres_user;
            }

            $stanzaName = 'db';

            // Stop PostgreSQL before restore
            instant_remote_process(
                ["docker exec {$this->container_name} bash -c 'pg_ctl stop -D \$(psql -U {$pgUser} -tAc \"SHOW data_directory;\") -m fast' 2>&1 || true"],
                $this->server,
                false,
                false,
                120,
                disableMultiplexing: true
            );

            // Build restore command
            $restoreCmd = "docker exec -u {$pgUser} {$this->container_name} pgbackrest --stanza={$stanzaName} --delta restore";

            if (filled($this->backupLabel)) {
                $escapedLabel = escapeshellarg($this->backupLabel);
                $restoreCmd .= " --set={$escapedLabel}";
            }

            $restoreCmd .= ' 2>&1';

            $output = instant_remote_process(
                [$restoreCmd],
                $this->server,
                true,
                false,
                $this->timeout,
                disableMultiplexing: true
            );

            $this->restore_output = "Restore completed:\n".trim($output);

            // Start PostgreSQL after restore
            instant_remote_process(
                ["docker restart {$this->container_name}"],
                $this->server,
                true,
                false,
                120,
                disableMultiplexing: true
            );

            // Wait for PostgreSQL to be ready
            $this->waitForPostgres($pgUser);

            $this->restore_output .= "\nPostgreSQL restarted and ready after restore.";

        } catch (Throwable $e) {
            $errorMsg = $this->error_output ?? $e->getMessage();

            // Try to restart PostgreSQL even if restore failed
            try {
                instant_remote_process(
                    ["docker restart {$this->container_name}"],
                    $this->server,
                    false,
                    false,
                    120,
                    disableMultiplexing: true
                );
            } catch (Throwable $restartError) {
                $errorMsg .= "\nFailed to restart PostgreSQL after restore failure: ".$restartError->getMessage();
            }

            $this->team?->notify(new BackupFailed($this->backup, $this->database, $errorMsg, 'pgbackrest-restore'));

            throw $e;
        } finally {
            if ($this->team) {
                BackupCreated::dispatch($this->team->id);
            }
        }
    }

    private function waitForPostgres(string $pgUser): void
    {
        $maxAttempts = 30;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = instant_remote_process(
                ["docker exec {$this->container_name} bash -c \"psql -U {$pgUser} -c 'SELECT 1' 2>/dev/null\" && echo 'READY' || echo 'NOT_READY'"],
                $this->server,
                false,
                false,
                10,
                disableMultiplexing: true
            );
            if (str_contains($result, 'READY')) {
                return;
            }
            sleep(2);
        }
        throw new \Exception('PostgreSQL did not become ready after restore within 60 seconds.');
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('PgBackRest restore permanently failed', [
            'job' => 'PgBackRestRestoreJob',
            'backup_id' => $this->backup->uuid,
            'database' => $this->database?->name ?? 'unknown',
            'server' => $this->server?->name ?? 'unknown',
            'error' => $exception?->getMessage(),
        ]);

        if ($this->team) {
            $this->team->notify(new BackupFailed($this->backup, $this->database, $exception?->getMessage() ?? 'Unknown error', 'pgbackrest-restore'));
        }
    }
}
