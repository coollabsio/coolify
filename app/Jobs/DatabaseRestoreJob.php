<?php

namespace App\Jobs;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\RestoreFailed;
use App\Notifications\Database\RestoreSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DatabaseRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $maxExceptions = 1;

    public ?Team $team = null;

    public Server $server;

    public StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|ServiceDatabase $database;

    public ?string $container_name = null;

    public ?string $restore_output = null;

    public ?string $error_output = null;

    public ?S3Storage $s3 = null;

    public ?string $postgres_password = null;

    public ?string $mongo_root_username = null;

    public ?string $mongo_root_password = null;

    public bool $s3_uploaded = false;

    public string $backup_log_uuid;

    public function __construct(
        public ScheduledDatabaseBackup $backup,
        public ScheduledDatabaseBackupExecution $backupExecution,
        public ?string $targetDatabase = null
    ) {
        $this->team = Team::find($backup->team_id);
    }

    public function handle(): void
    {
        try {
            $this->restore_output = "Starting database restore...\n";

            $this->database = $this->backup->database;
            if (is_null($this->database)) {
                throw new \Exception('Database not found');
            }

            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $this->server = $this->database->service->destination->server;
            } else {
                $this->server = $this->database->destination->server;
            }

            if ($this->backup->save_s3) {
                $this->s3 = $this->backup->s3;
            }

            $databaseType = $this->database->type();

            if (str($databaseType)->contains('postgres')) {
                $this->postgres_password = $this->database->postgres_password;
                $databaseName = str($this->database->name)->slug()->value();
                $this->container_name = $this->database->uuid;

                if ($this->backup->isPgBackRest()) {
                    $this->restore_pgbackrest_postgresql();
                } else {
                    $this->restore_pg_dump_postgresql();
                }

                $this->team?->notify(new RestoreSuccess($this->backup, $this->database, $this->backupExecution));
            } else {
                throw new \Exception('Restore is currently only supported for PostgreSQL databases');
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            $this->team?->notify(new RestoreFailed($this->backup, $this->database, $this->error_output ?? $this->restore_output ?? $e->getMessage()));
            throw $e;
        }
    }

    private function restore_pgbackrest_postgresql(): void
    {
        try {
            $commands = [];
            $stanzaName = $this->backup->getStanzaName();
            $processMax = $this->backup->pgbackrest_process_max ?? 1;
            $pgbackrestImage = 'woblerr/pgbackrest:2.48';

            $databaseName = str($this->database->name)->slug()->value();
            $this->directory_name = $databaseName.'-'.$this->container_name;
            $backup_dir = backup_dir().'/databases/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->directory_name;

            $pgbackrestConfigDir = escapeshellarg($backup_dir.'/.pgbackrest');
            $config = $this->generate_pgbackrest_config($stanzaName);
            $configPath = trim($pgbackrestConfigDir, "'").'/'.'pgbackrest.conf';
            $quotedConfigPath = escapeshellarg($configPath);

            $escapedConfig = base64_encode($config);
            $commands[] = "echo ".escapeshellarg($escapedConfig)." | base64 -d > $quotedConfigPath";

            $containerName = escapeshellarg($this->container_name);
            $pgDataDir = instant_remote_process(["docker inspect $containerName --format='{{range .Mounts}}{{if eq .Destination \"/var/lib/postgresql/data\"}}{{.Source}}{{end}}{{end}}'"], $this->server, false);
            $pgDataDir = trim($pgDataDir);

            if (empty($pgDataDir)) {
                throw new \Exception('Could not determine PostgreSQL data directory');
            }

            $quotedPgDataDir = escapeshellarg($pgDataDir);
            $quotedBackupDir = escapeshellarg($backup_dir);
            $quotedStanzaName = escapeshellarg($stanzaName);

            $this->add_to_restore_output("Stopping PostgreSQL container for restore...");
            $commands[] = "docker stop $containerName";
            instant_remote_process($commands, $this->server);
            $commands = [];

            $this->add_to_restore_output("Restoring database from pgBackRest backup...");

            $restoreCommand = "docker run --rm ";
            $restoreCommand .= "-v $quotedConfigPath:/etc/pgbackrest/pgbackrest.conf:ro ";
            $restoreCommand .= "-v $quotedPgDataDir:/var/lib/postgresql/data:rw ";
            if ($this->s3) {
                $restoreCommand .= "-v $quotedBackupDir:/var/lib/pgbackrest:ro ";
            } else {
                $restoreCommand .= "-v $quotedBackupDir:/var/lib/pgbackrest:rw ";
            }
            $restoreCommand .= "$pgbackrestImage ";
            $restoreCommand .= "pgbackrest restore --stanza=$quotedStanzaName ";
            $restoreCommand .= '--process-max='.escapeshellarg((string) $processMax).' ';
            $restoreCommand .= '--delta --log-level-console=info';

            $commands[] = $restoreCommand;
            $restoreOutput = instant_remote_process($commands, $this->server);
            $this->add_to_restore_output($restoreOutput);
            $commands = [];

            $this->add_to_restore_output("Starting PostgreSQL container after restore...");
            $commands[] = "docker start $containerName";
            instant_remote_process($commands, $this->server);

            $this->add_to_restore_output("Waiting for PostgreSQL to be ready...");
            sleep(5);

            $this->add_to_restore_output("Database restore completed successfully");

            $this->restore_output = trim($this->restore_output);
            if ($this->restore_output === '') {
                $this->restore_output = null;
            }
        } catch (\Throwable $e) {
            $startCommand = "docker start ".escapeshellarg($this->container_name);
            instant_remote_process([$startCommand], $this->server, false);

            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function restore_pg_dump_postgresql(): void
    {
        try {
            $backupFile = $this->backupExecution->filename;

            if (empty($backupFile)) {
                throw new \Exception('Backup file not found in execution record');
            }

            $this->add_to_restore_output("Checking if backup file exists: $backupFile");

            $checkCommand = "[ -f ".escapeshellarg($backupFile)." ] && echo 'exists' || echo 'missing'";
            $fileCheck = instant_remote_process([$checkCommand], $this->server, false);

            if (! str_contains($fileCheck, 'exists')) {
                throw new \Exception('Backup file does not exist on server: '.$backupFile);
            }

            $targetDb = $this->targetDatabase ?? $this->database->postgres_db;
            $this->add_to_restore_output("Restoring database: $targetDb from pg_dump backup");

            $restoreCommand = 'docker exec';
            if ($this->postgres_password) {
                $restoreCommand .= " -e PGPASSWORD=\"{$this->postgres_password}\"";
            }

            if (str_contains($backupFile, 'pg-dump-all')) {
                $restoreCommand .= " -i {$this->container_name} psql --username {$this->database->postgres_user} < $backupFile";
            } else {
                $restoreCommand .= " -i {$this->container_name} pg_restore --username {$this->database->postgres_user} --dbname=$targetDb --clean --if-exists --no-owner --no-acl < $backupFile";
            }

            $commands[] = $restoreCommand;
            $this->restore_output = instant_remote_process($commands, $this->server);

            $this->add_to_restore_output("Database restore completed successfully");

            $this->restore_output = trim($this->restore_output);
            if ($this->restore_output === '') {
                $this->restore_output = null;
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function generate_pgbackrest_config(string $stanzaName): string
    {
        $config = "[global]\n";
        $config .= "log-level-console=info\n";
        $config .= "log-level-file=info\n";
        $config .= "process-max={$this->backup->pgbackrest_process_max}\n";

        if ($this->backup->pgbackrest_retention_full) {
            $config .= "repo1-retention-full={$this->backup->pgbackrest_retention_full}\n";
        }

        if ($this->backup->pgbackrest_retention_diff) {
            $config .= "repo1-retention-diff={$this->backup->pgbackrest_retention_diff}\n";
        }

        if ($this->s3) {
            $config .= "repo1-type=s3\n";
            $config .= "repo1-s3-bucket={$this->s3->bucket}\n";
            $config .= "repo1-s3-endpoint={$this->s3->endpoint}\n";
            $config .= "repo1-s3-region={$this->s3->region}\n";
            $config .= "repo1-path=/pgbackrest/{$stanzaName}\n";
            $config .= "repo1-s3-key={$this->s3->key}\n";
            $config .= "repo1-s3-key-secret={$this->s3->secret}\n";
        } else {
            $config .= "repo1-path=/var/lib/pgbackrest\n";
        }

        if ($this->backup->pgbackrest_block_incremental) {
            $config .= "repo1-block=y\n";
        }

        $config .= "\n[{$stanzaName}]\n";
        $config .= "pg1-path=/var/lib/postgresql/data\n";

        if ($this->postgres_password) {
            $config .= "pg1-port=5432\n";
        }

        return $config;
    }

    private function add_to_restore_output($output): void
    {
        if ($this->restore_output) {
            $this->restore_output = $this->restore_output."\n".$output;
        } else {
            $this->restore_output = $output;
        }
    }

    private function add_to_error_output($output): void
    {
        if ($this->error_output) {
            $this->error_output = $this->error_output."\n".$output;
        } else {
            $this->error_output = $output;
        }
    }
}