<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BackupDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Team|null $team = null;
    public Server $server;
    public ScheduledDatabaseBackup|null $backup;
    public string $database_type;
    public StandalonePostgresql $database;
    public string $status;

    public function __construct($backup)
    {
        $this->backup = $backup;
        $this->team = Team::find($backup->team_id);
        $this->database = $this->backup->database->first();
        $this->database_type = $this->database->type();
        $this->server = $this->database->destination->server;
        $this->status = $this->database->status;
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->backup->id)];
    }

    public function uniqueId(): int
    {
        return $this->backup->id;
    }

    public function handle()
    {
        if ($this->status !== 'running') {
            ray('database not running');
            return;
        }
        if ($this->database_type === 'standalone-postgresql') {
            $this->backup_standalone_postgresql();
        }
    }

    private function backup_standalone_postgresql()
    {
        try {
            $backup_filename = backup_dir() . "/{$this->database->uuid}/dumpall-" . Carbon::now()->timestamp . ".sql";
            $commands[] = "mkdir -p " . backup_dir();
            $commands[] = "mkdir -p " . backup_dir() . "/{$this->database->uuid}";
            $commands[] = "docker exec {$this->database->uuid} pg_dumpall -U {$this->database->postgres_user} > $backup_filename";
            instant_remote_process($commands, $this->server);
            ray('Backup done for ' . $this->database->uuid . ' at ' . $this->server->name . ':' . $backup_filename);
            if (!$this->backup->keep_locally) {
                $commands[] = "rm -rf $backup_filename";
                instant_remote_process($commands, $this->server);
            }
        } catch (Throwable $th) {
            ray($th);
            //throw $th;
        }
    }
}
