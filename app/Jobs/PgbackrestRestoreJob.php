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
        $containerName = $this->database->getPgbackrestContainerName();
        $postgresContainer = $this->database->uuid;
        $stanzaName = $this->database->getPgbackrestStanzaName();

        Log::info('Starting pgBackRest restore', [
            'database_id' => $this->database->id,
            'backup_label' => $this->backupLabel,
            'target_time' => $this->targetTime,
        ]);

        $this->database->update(['status' => 'restoring']);

        try {
            $stopCommands = [
                "docker stop {$postgresContainer} 2>/dev/null || true",
            ];
            instant_remote_process($stopCommands, $server, false);

            $pgDataVolume = $this->getPostgresDataVolumeName();
            $restoreContainerName = "pgbackrest-restore-{$this->database->uuid}";

            $restoreCommand = $this->buildRestoreCommand($stanzaName);

            $commands = [
                "docker stop {$restoreContainerName} 2>/dev/null || true",
                "docker rm -f {$restoreContainerName} 2>/dev/null || true",
                "docker run --rm --name {$restoreContainerName} ".
                    "--network {$this->database->destination->network} ".
                    "-v {$this->database->getPgbackrestConfigDir()}/pgbackrest.conf:/etc/pgbackrest/pgbackrest.conf:ro ".
                    "-v {$this->database->getPgbackrestRepoDir()}:/var/lib/pgbackrest ".
                    "-v {$pgDataVolume}:/var/lib/postgresql/data ".
                    '-e PGBACKREST_CONFIG=/etc/pgbackrest/pgbackrest.conf '.
                    config('constants.pgbackrest.image').':'.config('constants.pgbackrest.version').' '.
                    $restoreCommand,
            ];

            $output = instant_remote_process($commands, $server);

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

            $team = $this->database->team();
            $team?->notify(new PgbackrestRestoreSuccess($this->database, $this->backupLabel));

        } catch (\Throwable $e) {
            Log::error('pgBackRest restore failed', [
                'database_id' => $this->database->id,
                'error' => $e->getMessage(),
            ]);

            $this->database->update(['status' => 'error']);

            $team = $this->database->team();
            $team?->notify(new PgbackrestRestoreFailed($this->database, $e->getMessage(), $this->backupLabel));

            throw $e;
        }
    }

    private function buildRestoreCommand(string $stanzaName): string
    {
        $command = 'pgbackrest --stanza='.escapeshellarg($stanzaName);

        if ($this->backupLabel) {
            $command .= ' --set='.escapeshellarg($this->backupLabel);
        }

        if ($this->targetTime) {
            $command .= ' --type=time --target='.escapeshellarg($this->targetTime);
        }

        $command .= ' --delta --link-all restore';

        return $command;
    }

    private function getPostgresDataVolumeName(): string
    {
        $persistentStorage = $this->database->persistentStorages()
            ->where('mount_path', '/var/lib/postgresql/data')
            ->first();

        if ($persistentStorage && $persistentStorage->host_path) {
            return $persistentStorage->host_path;
        }

        return $persistentStorage?->name ?? "postgres-data-{$this->database->uuid}";
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
