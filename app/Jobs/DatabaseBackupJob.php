<?php

namespace App\Jobs;

use App\Events\BackupCreated;
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
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use App\Notifications\Database\BackupSuccessWithS3Warning;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Visus\Cuid2\Cuid2;

class DatabaseBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public ?Team $team = null;

    public Server $server;

    public StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|ServiceDatabase $database;

    public ?string $container_name = null;

    public ?string $directory_name = null;

    public ?ScheduledDatabaseBackupExecution $backup_log = null;

    public string $backup_status = 'failed';

    public ?string $backup_location = null;

    public string $backup_dir;

    public string $backup_file;

    public int $size = 0;

    public ?string $backup_output = null;

    public ?string $error_output = null;

    public bool $s3_uploaded = false;

    public ?string $postgres_password = null;

    public ?string $mongo_root_username = null;

    public ?string $mongo_root_password = null;

    public ?S3Storage $s3 = null;

    public $timeout = 3600;

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
            if (data_get($this->backup, 'database_type') === \App\Models\ServiceDatabase::class) {
                $this->database = data_get($this->backup, 'database');
                $this->server = $this->database->service->server;
                $this->s3 = $this->backup->s3;
            } else {
                $this->database = data_get($this->backup, 'database');
                $this->server = $this->database->destination->server;
                $this->s3 = $this->backup->s3;
            }
            if (is_null($this->server)) {
                throw new \Exception('Server not found?!');
            }
            if (is_null($this->database)) {
                throw new \Exception('Database not found?!');
            }

            BackupCreated::dispatch($this->team->id);

            $status = str(data_get($this->database, 'status'));
            if (! $status->startsWith('running') && $this->database->id !== 0) {
                return;
            }
            if (data_get($this->backup, 'database_type') === \App\Models\ServiceDatabase::class) {
                $databaseType = $this->database->databaseType();
                $serviceUuid = $this->database->service->uuid;
                $serviceName = str($this->database->service->name)->slug();
                if (str($databaseType)->contains('postgres')) {
                    $this->container_name = "{$this->database->name}-$serviceUuid";
                    $this->directory_name = $serviceName.'-'.$this->container_name;
                    $commands[] = "docker exec $this->container_name env | grep POSTGRES_";
                    $envs = instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
                    $envs = str($envs)->explode("\n");

                    $user = $envs->filter(function ($env) {
                        return str($env)->startsWith('POSTGRES_USER=');
                    })->first();
                    if ($user) {
                        $this->database->postgres_user = str($user)->after('POSTGRES_USER=')->value();
                    } else {
                        $this->database->postgres_user = 'postgres';
                    }

                    $this->postgres_password = $envs->filter(function ($env) {
                        return str($env)->startsWith('POSTGRES_PASSWORD=');
                    })->first();
                    if ($this->postgres_password) {
                        $this->postgres_password = str($this->postgres_password)->after('POSTGRES_PASSWORD=')->value();
                    }
                } elseif (str($databaseType)->contains('mysql')) {
                    $this->container_name = "{$this->database->name}-$serviceUuid";
                    $this->directory_name = $serviceName.'-'.$this->container_name;
                    $commands[] = "docker exec $this->container_name env | grep MYSQL_";
                    $envs = instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
                    $envs = str($envs)->explode("\n");

                    $rootPassword = $envs->filter(function ($env) {
                        return str($env)->startsWith('MYSQL_ROOT_PASSWORD=');
                    })->first();
                    if ($rootPassword) {
                        $this->database->mysql_root_password = str($rootPassword)->after('MYSQL_ROOT_PASSWORD=')->value();
                    }
                } elseif (str($databaseType)->contains('mariadb')) {
                    $this->container_name = "{$this->database->name}-$serviceUuid";
                    $this->directory_name = $serviceName.'-'.$this->container_name;
                    $commands[] = "docker exec $this->container_name env";
                    $envs = instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
                    $envs = str($envs)->explode("\n");
                    $rootPassword = $envs->filter(function ($env) {
                        return str($env)->startsWith('MARIADB_ROOT_PASSWORD=');
                    })->first();
                    if ($rootPassword) {
                        $this->database->mariadb_root_password = str($rootPassword)->after('MARIADB_ROOT_PASSWORD=')->value();
                    } else {
                        $rootPassword = $envs->filter(function ($env) {
                            return str($env)->startsWith('MYSQL_ROOT_PASSWORD=');
                        })->first();
                        if ($rootPassword) {
                            $this->database->mariadb_root_password = str($rootPassword)->after('MYSQL_ROOT_PASSWORD=')->value();
                        }
                    }
                } elseif (str($databaseType)->contains('mongo')) {
                    $this->container_name = "{$this->database->name}-$serviceUuid";
                    $this->directory_name = $serviceName.'-'.$this->container_name;

                    // Try to extract MongoDB credentials from environment variables
                    try {
                        $commands = [];
                        $commands[] = "docker exec $this->container_name env | grep MONGO_INITDB_";
                        $envs = instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);

                        if (filled($envs)) {
                            $envs = str($envs)->explode("\n");
                            $rootPassword = $envs->filter(function ($env) {
                                return str($env)->startsWith('MONGO_INITDB_ROOT_PASSWORD=');
                            })->first();
                            if ($rootPassword) {
                                $this->mongo_root_password = str($rootPassword)->after('MONGO_INITDB_ROOT_PASSWORD=')->value();
                            }
                            $rootUsername = $envs->filter(function ($env) {
                                return str($env)->startsWith('MONGO_INITDB_ROOT_USERNAME=');
                            })->first();
                            if ($rootUsername) {
                                $this->mongo_root_username = str($rootUsername)->after('MONGO_INITDB_ROOT_USERNAME=')->value();
                            }
                        }

                    } catch (\Throwable $e) {
                        // Continue without env vars - will be handled in backup_standalone_mongodb method
                    }
                }
            } else {
                $databaseName = str($this->database->name)->slug()->value();
                $this->container_name = $this->database->uuid;
                $this->directory_name = $databaseName.'-'.$this->container_name;
                $databaseType = $this->database->type();
            }

            $this->backup_dir = backup_dir().'/databases/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->directory_name;
            if ($this->database->name === 'coolify-db') {
                $databasesToBackup = ['coolify'];
                $this->directory_name = $this->container_name = 'coolify-db';
                $ip = Str::slug($this->server->ip);
                $this->backup_dir = backup_dir().'/coolify'."/coolify-db-$ip";
            }

            // Generate unique UUID for backup execution
            $attempts = 0;
            do {
                $this->backup_log_uuid = (string) new Cuid2;
                $exists = ScheduledDatabaseBackupExecution::where('uuid', $this->backup_log_uuid)->exists();
                $attempts++;
                if ($attempts >= 3 && $exists) {
                    throw new \Exception('Unable to generate unique UUID for backup execution after 3 attempts');
                }
            } while ($exists);

            $size = 0;
            $localBackupSucceeded = false;
            $s3UploadError = null;

            // Step 1: Create local backup (always dump all databases)
            try {
                if (str($databaseType)->contains('postgres')) {
                    $this->backup_file = '/pg-dump-all-'.Carbon::now()->timestamp.'.sql.gz';
                    $this->backup_location = $this->backup_dir.$this->backup_file;
                    $this->backup_log = ScheduledDatabaseBackupExecution::create([
                        'uuid' => $this->backup_log_uuid,
                        'filename' => $this->backup_location,
                        'scheduled_database_backup_id' => $this->backup->id,
                        'local_storage_deleted' => false,
                    ]);
                    $this->backup_standalone_postgresql();
                } elseif (str($databaseType)->contains('mongo')) {
                    $this->backup_file = '/mongo-dump-all-'.Carbon::now()->timestamp.'.tar.gz';
                    $this->backup_location = $this->backup_dir.$this->backup_file;
                    $this->backup_log = ScheduledDatabaseBackupExecution::create([
                        'uuid' => $this->backup_log_uuid,
                        'filename' => $this->backup_location,
                        'scheduled_database_backup_id' => $this->backup->id,
                        'local_storage_deleted' => false,
                    ]);
                    $this->backup_standalone_mongodb();
                } elseif (str($databaseType)->contains('mysql')) {
                    $this->backup_file = '/mysql-dump-all-'.Carbon::now()->timestamp.'.sql.gz';
                    $this->backup_location = $this->backup_dir.$this->backup_file;
                    $this->backup_log = ScheduledDatabaseBackupExecution::create([
                        'uuid' => $this->backup_log_uuid,
                        'filename' => $this->backup_location,
                        'scheduled_database_backup_id' => $this->backup->id,
                        'local_storage_deleted' => false,
                    ]);
                    $this->backup_standalone_mysql();
                } elseif (str($databaseType)->contains('mariadb')) {
                    $this->backup_file = '/mariadb-dump-all-'.Carbon::now()->timestamp.'.sql.gz';
                    $this->backup_location = $this->backup_dir.$this->backup_file;
                    $this->backup_log = ScheduledDatabaseBackupExecution::create([
                        'uuid' => $this->backup_log_uuid,
                        'filename' => $this->backup_location,
                        'scheduled_database_backup_id' => $this->backup->id,
                        'local_storage_deleted' => false,
                    ]);
                    $this->backup_standalone_mariadb();
                } else {
                    throw new \Exception('Unsupported database type');
                }

                $size = $this->calculate_size();

                // Verify local backup succeeded
                if ($size > 0) {
                    $localBackupSucceeded = true;
                } else {
                    throw new \Exception('Local backup file is empty or was not created');
                }
            } catch (\Throwable $e) {
                // Local backup failed
                if ($this->backup_log) {
                    $this->backup_log->update([
                        'status' => 'failed',
                        'message' => $this->error_output ?? $this->backup_output ?? $e->getMessage(),
                        'size' => $size,
                        'filename' => null,
                        's3_uploaded' => null,
                    ]);
                }
                $this->team?->notify(new BackupFailed($this->backup, $this->database, $this->error_output ?? $this->backup_output ?? $e->getMessage()));
                throw $e;
            }

            // Step 2: Upload to S3 if enabled (independent of local backup)
            $localStorageDeleted = false;
            if ($this->backup->save_s3 && $localBackupSucceeded) {
                try {
                    $this->upload_to_s3();

                    // If local backup is disabled, delete the local file immediately after S3 upload
                    if ($this->backup->disable_local_backup) {
                        deleteBackupsLocally($this->backup_location, $this->server);
                        $localStorageDeleted = true;
                    }
                } catch (\Throwable $e) {
                    // S3 upload failed but local backup succeeded
                    $s3UploadError = $e->getMessage();
                }
            }

            // Step 3: Update status and send notifications based on results
            if ($localBackupSucceeded) {
                $message = $this->backup_output;

                if ($s3UploadError) {
                    $message = $message
                        ? $message."\n\nWarning: S3 upload failed: ".$s3UploadError
                        : 'Warning: S3 upload failed: '.$s3UploadError;
                }

                $this->backup_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'size' => $size,
                    's3_uploaded' => $this->backup->save_s3 ? $this->s3_uploaded : null,
                    'local_storage_deleted' => $localStorageDeleted,
                ]);

                // Send appropriate notification
                if ($s3UploadError) {
                    $this->team->notify(new BackupSuccessWithS3Warning($this->backup, $this->database, $s3UploadError));
                } else {
                    $this->team->notify(new BackupSuccess($this->backup, $this->database));
                }
            }
            if ($this->backup_log && $this->backup_log->status === 'success') {
                removeOldBackups($this->backup);
            }
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            if ($this->team) {
                BackupCreated::dispatch($this->team->id);
            }
            if ($this->backup_log) {
                $this->backup_log->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            }
        }
    }

    private function backup_standalone_mongodb(): void
    {
        try {
            $url = $this->database->internal_db_url;
            if (blank($url)) {
                // For service-based MongoDB, try to build URL from environment variables
                if (filled($this->mongo_root_username) && filled($this->mongo_root_password)) {
                    // Use container name instead of server IP for service-based MongoDB
                    $url = "mongodb://{$this->mongo_root_username}:{$this->mongo_root_password}@{$this->container_name}:27017";
                } else {
                    // If no environment variables are available, throw an exception
                    throw new \Exception('MongoDB credentials not found. Ensure MONGO_INITDB_ROOT_USERNAME and MONGO_INITDB_ROOT_PASSWORD environment variables are available in the container.');
                }
            }
            \Log::info('MongoDB backup URL configured', ['has_url' => filled($url), 'using_env_vars' => blank($this->database->internal_db_url)]);

            $commands[] = 'mkdir -p '.$this->backup_dir;
            if (str($this->database->image)->startsWith('mongo:4')) {
                $commands[] = "docker exec $this->container_name mongodump --uri=\"$url\" --gzip --archive > $this->backup_location";
            } else {
                $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=\"$url\" --gzip --archive > $this->backup_location";
            }

            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_postgresql(): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $backupCommand = 'docker exec';
            if ($this->postgres_password) {
                $backupCommand .= " -e PGPASSWORD=\"{$this->postgres_password}\"";
            }
            $backupCommand .= " $this->container_name pg_dumpall --clean --if-exists --username {$this->database->postgres_user} | gzip > $this->backup_location";

            $commands[] = $backupCommand;
            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mysql(): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $commands[] = "docker exec $this->container_name mysqldump -u root -p\"{$this->database->mysql_root_password}\" --all-databases --single-transaction --quick --lock-tables=false | gzip > $this->backup_location";
            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mariadb(): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $commands[] = "docker exec $this->container_name mariadb-dump -u root -p\"{$this->database->mariadb_root_password}\" --all-databases --single-transaction --quick --lock-tables=false | gzip > $this->backup_location";
            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function add_to_backup_output($output): void
    {
        if ($this->backup_output) {
            $this->backup_output = $this->backup_output."\n".$output;
        } else {
            $this->backup_output = $output;
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

    private function calculate_size()
    {
        return instant_remote_process(["du -b $this->backup_location | cut -f1"], $this->server, false, false, null, disableMultiplexing: true);
    }

    private function upload_to_s3(): void
    {
        try {
            if (is_null($this->s3)) {
                return;
            }
            $key = $this->s3->key;
            $secret = $this->s3->secret;
            // $region = $this->s3->region;
            $bucket = $this->s3->bucket;
            $endpoint = $this->s3->endpoint;
            $this->s3->testConnection(shouldSave: true);
            if (data_get($this->backup, 'database_type') === \App\Models\ServiceDatabase::class) {
                $network = $this->database->service->destination->network;
            } else {
                $network = $this->database->destination->network;
            }

            $fullImageName = $this->getFullImageName();

            $containerExists = instant_remote_process(["docker ps -a -q -f name=backup-of-{$this->backup_log_uuid}"], $this->server, false, false, null, disableMultiplexing: true);
            if (filled($containerExists)) {
                instant_remote_process(["docker rm -f backup-of-{$this->backup_log_uuid}"], $this->server, false, false, null, disableMultiplexing: true);
            }

            if (isDev()) {
                if ($this->database->name === 'coolify-db') {
                    $backup_location_from = '/var/lib/docker/volumes/coolify_dev_backups_data/_data/coolify/coolify-db-'.$this->server->ip.$this->backup_file;
                    $commands[] = "docker run -d --network {$network} --name backup-of-{$this->backup_log_uuid} --rm -v $backup_location_from:$this->backup_location:ro {$fullImageName}";
                } else {
                    $backup_location_from = '/var/lib/docker/volumes/coolify_dev_backups_data/_data/databases/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->directory_name.$this->backup_file;
                    $commands[] = "docker run -d --network {$network} --name backup-of-{$this->backup_log_uuid} --rm -v $backup_location_from:$this->backup_location:ro {$fullImageName}";
                }
            } else {
                $commands[] = "docker run -d --network {$network} --name backup-of-{$this->backup_log_uuid} --rm -v $this->backup_location:$this->backup_location:ro {$fullImageName}";
            }

            // Escape S3 credentials to prevent command injection
            $escapedEndpoint = escapeshellarg($endpoint);
            $escapedKey = escapeshellarg($key);
            $escapedSecret = escapeshellarg($secret);

            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc alias set temporary {$escapedEndpoint} {$escapedKey} {$escapedSecret}";
            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc cp $this->backup_location temporary/$bucket{$this->backup_dir}/";
            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);

            $this->s3_uploaded = true;
        } catch (\Throwable $e) {
            $this->s3_uploaded = false;
            $this->add_to_error_output($e->getMessage());
            throw $e;
        } finally {
            $command = "docker rm -f backup-of-{$this->backup_log_uuid}";
            instant_remote_process([$command], $this->server, true, false, null, disableMultiplexing: true);
        }
    }

    private function getFullImageName(): string
    {
        $helperImage = config('constants.coolify.helper_image');
        $latestVersion = getHelperVersion();

        return "{$helperImage}:{$latestVersion}";
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('DatabaseBackup permanently failed', [
            'job' => 'DatabaseBackupJob',
            'backup_id' => $this->backup->uuid,
            'database' => $this->database?->name ?? 'unknown',
            'database_type' => get_class($this->database ?? new \stdClass),
            'server' => $this->server?->name ?? 'unknown',
            'total_attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        $log = ScheduledDatabaseBackupExecution::where('uuid', $this->backup_log_uuid)->first();

        if ($log) {
            $log->update([
                'status' => 'failed',
                'message' => 'Job permanently failed after '.$this->attempts().' attempts: '.($exception?->getMessage() ?? 'Unknown error'),
                'size' => 0,
                'filename' => null,
                'finished_at' => Carbon::now(),
            ]);
        }

        // Notify team about permanent failure
        if ($this->team) {
            $output = $this->backup_output ?? $exception?->getMessage() ?? 'Unknown error';
            $this->team->notify(new BackupFailed($this->backup, $this->database, $output));
        }
    }
}
