<?php

namespace App\Jobs;

use App\Actions\Database\StartPostgresql;
use App\Models\StandalonePostgresql;
use App\Notifications\Database\PgbackrestRestoreFailed;
use App\Notifications\Database\PgbackrestRestoreSuccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PgbackrestRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;

    public $tries = 1;

    public function __construct(
        public StandalonePostgresql $database,
        public ?string $backupLabel = null,
        public ?string $targetTime = null,
        public bool $restartAfter = true
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $server = $this->database->destination->server;
        $postgresContainer = $this->database->uuid;
        $stanzaName = $this->database->getPgbackrestStanzaName();

        Log::info('Starting pgBackRest restore', [
            'database_id' => $this->database->id,
            'backup_label' => $this->backupLabel,
            'target_time' => $this->targetTime,
        ]);

        $this->database->update(['status' => 'restoring']);

        try {
            $restoreCommand = $this->buildRestoreCommand($stanzaName);

            $stopPostgresCommands = [
                "docker exec {$postgresContainer} su postgres -c 'pg_ctl stop -D /var/lib/postgresql/data -m fast' 2>/dev/null || true",
                "sleep 2",
            ];
            instant_remote_process($stopPostgresCommands, $server, false);

            fixPgbackrestPermissions($this->database);

            $output = execPgbackrest($this->database, $restoreCommand, throwError: true);

            Log::info('pgBackRest restore completed', [
                'database_id' => $this->database->id,
                'output' => $output,
            ]);

            if ($this->restartAfter) {
                Log::info('Restarting PostgreSQL after restore', ['database_id' => $this->database->id]);
                StartPostgresql::run($this->database);
            } else {
                $this->database->update(['status' => 'exited']);
            }

            $team = $this->database->team;
            $team?->notify(new PgbackrestRestoreSuccess($this->database, $this->backupLabel));

        } catch (\Throwable $e) {
            Log::error('pgBackRest restore failed', [
                'database_id' => $this->database->id,
                'error' => $e->getMessage(),
            ]);

            $this->database->update(['status' => 'error']);

            $team = $this->database->team;
            $team?->notify(new PgbackrestRestoreFailed($this->database, $e->getMessage(), $this->backupLabel));

            throw $e;
        }
    }

    private function buildRestoreCommand(string $stanzaName): string
    {
        $command = '--stanza='.escapeshellarg($stanzaName);

        if ($this->backupLabel) {
            $command .= ' --set='.escapeshellarg($this->backupLabel);
        }

        if ($this->targetTime) {
            $command .= ' --type=time --target='.escapeshellarg($this->targetTime);
        } else {
            $command .= ' --type=immediate';
        }

        $command .= ' --target-action=promote --delta --link-all restore';

        return $command;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('pgBackRest restore job failed', [
            'database_id' => $this->database->id,
            'error' => $exception->getMessage(),
        ]);

        $this->database->update(['status' => 'error']);
    }
}
