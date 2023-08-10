<?php

namespace App\Jobs;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Team|null $team = null;
    public Server $server;
    public ScheduledDatabaseBackup|null $backup;
    public string $database_type;
    public StandalonePostgresql $database;
    public string $database_status;

    public ScheduledDatabaseBackupExecution|null $backup_log = null;
    public string $backup_status;
    public string|null $backup_filename = null;
    public int $size = 0;
    public string|null $backup_output = null;
    public S3Storage $s3;

    public function __construct($backup)
    {
        $this->backup = $backup;
        $this->team = Team::find($backup->team_id);
        $this->database = $this->backup->database->first();
        $this->database_type = $this->database->type();
        $this->server = $this->database->destination->server;
        $this->database_status = $this->database->status;
        $this->s3 = $this->team->s3;
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
        if ($this->database_status !== 'running') {
            ray('database not running');
            return;
        }
        $this->backup_filename = backup_dir() . "/{$this->database->uuid}/dumpall-" . Carbon::now()->timestamp . ".sql";

        $this->backup_log = ScheduledDatabaseBackupExecution::create([
            'filename' => $this->backup_filename,
            'scheduled_database_backup_id' => $this->backup->id,
        ]);
        if ($this->database_type === 'standalone-postgresql') {
            $this->backup_standalone_postgresql();
        }
        $this->calculate_size();
        $this->remove_old_backups();
        if ($this->backup->save_s3) {
//            $this->upload_to_s3();
        }
        $this->save_backup_logs();
        // TODO: Notify user
    }

    private function backup_standalone_postgresql()
    {
        try {
            $commands[] = "mkdir -p " . backup_dir();
            $commands[] = "mkdir -p " . backup_dir() . "/{$this->database->uuid}";
            $commands[] = "docker exec {$this->database->uuid} pg_dumpall -U {$this->database->postgres_user} > $this->backup_filename";

            $this->backup_output = instant_remote_process($commands, $this->server);

            $this->backup_output = trim($this->backup_output);

            if ($this->backup_output === '') {
                $this->backup_output = null;
            }

            ray('Backup done for ' . $this->database->uuid . ' at ' . $this->server->name . ':' . $this->backup_filename);

            $this->backup_status = 'success';
            throw new \Error('test');
            $this->team->notify(new BackupSuccess($this->backup, $this->database));
        } catch (Throwable $th) {
            $this->backup_status = 'failed';
            $this->add_to_backup_output($th->getMessage());
            ray('Backup failed for ' . $this->database->uuid . ' at ' . $this->server->name . ':' . $this->backup_filename . '\n\nError:' . $th->getMessage());
            $this->team->notify(new BackupFailed($this->backup, $this->database));
        } finally {
            $this->backup_log->update([
                'status' => $this->backup_status,
            ]);
        }
    }

    private function add_to_backup_output($output)
    {
        if ($this->backup_output) {
            $this->backup_output = $this->backup_output . "\n" . $output;
        } else {
            $this->backup_output = $output;
        }
    }

    private function calculate_size()
    {
        $this->size = instant_remote_process(["du -b $this->backup_filename | cut -f1"], $this->server);
    }

    private function remove_old_backups()
    {
        if ($this->backup->number_of_backups_locally === 0) {
            $deletable = $this->backup->executions()->where('status', 'success');
        } else {
            $deletable = $this->backup->executions()->where('status', 'success')->orderByDesc('created_at')->skip($this->backup->number_of_backups_locally);
        }
        foreach ($deletable->get() as $execution) {
            delete_backup_locally($execution->filename, $this->server);
            $execution->delete();
        }
    }

    private function save_backup_logs()
    {
        $this->backup_log->update([
            'status' => $this->backup_status,
            'message' => $this->backup_output,
            'size' => $this->size,
        ]);

    }

    private function upload_to_s3()
    {
        try {
            if (is_null($this->s3)) {
                return;
            }
            $key = $this->s3->key;
            $secret = $this->s3->secret;
//            $region = $this->s3->region;
            $bucket = $this->s3->bucket;
            $endpoint = $this->s3->endpoint;
            $backup_dir = backup_dir() . "/{$this->database->uuid}";

            $base_command = "docker run -t --network {$this->database->destination->network} -v {$this->backup_filename}:{$this->backup_filename}:ro --rm --entrypoint=/bin/sh minio/mc -c \"mc config host add temporary {$endpoint} $key $secret && mc cp $this->backup_filename temporary/$bucket/$backup_dir/ \"";

            instant_remote_process([$base_command], $this->server);

            $this->add_to_backup_output('Uploaded to S3.');
        } catch (\Throwable $th) {
            $this->add_to_backup_output($th->getMessage());
            ray($th->getMessage());
        }
    }
}
