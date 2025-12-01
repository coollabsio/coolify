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
        $stanzaName = $this->database->getPgbackrestStanzaName();
        $containerName = $this->database->getPgbackrestContainerName();

        $this->backup_log = ScheduledDatabaseBackupExecution::create([
            'uuid' => $this->backup_log_uuid,
            'database_name' => $this->database->postgres_db,
            'filename' => "pgbackrest:{$stanzaName}:{$backupType}",
            'scheduled_database_backup_id' => $this->backup->id,
            'local_storage_deleted' => false,
        ]);

        try {
            if (! isPgbackrestContainerRunning($this->database)) {
                throw new \Exception('pgBackRest container is not running');
            }

            $backupCommand = "docker exec {$containerName} pgbackrest --stanza={$stanzaName} --type={$backupType} --no-online --force backup";

            $output = instant_remote_process([$backupCommand], $this->server);

            $lastBackup = getPgbackrestLatestBackup($this->database) ?? [];

            $this->backup_log->update([
                'status' => 'success',
                'message' => $output,
                'size' => $lastBackup['size'] ?? 0,
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
}
