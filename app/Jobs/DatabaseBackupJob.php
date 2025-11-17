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
            $databasesToBackup = null;

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
                    $envs = instant_remote_process($commands, $this->server);
                    $envs = str($envs)->explode("\n");

                    $user = $envs->filter(function ($env) {
                        return str($env)->startsWith('POSTGRES_USER=');
                    })->first();
                    if ($user) {
                        $this->database->postgres_user = str($user)->after('POSTGRES_USER=')->value();
                    } else {
                        $this->database->postgres_user = 'postgres';
                    }

                    $db = $envs->filter(function ($env) {
                        return str($env)->startsWith('POSTGRES_DB=');
                    })->first();

                    if ($db) {
                        $databasesToBackup = str($db)->after('POSTGRES_DB=')->value();
                    } else {
                        $databasesToBackup = $this->database->postgres_user;
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
                    $envs = instant_remote_process($commands, $this->server);
                    $envs = str($envs)->explode("\n");

                    $rootPassword = $envs->filter(function ($env) {
                        return str($env)->startsWith('MYSQL_ROOT_PASSWORD=');
                    })->first();
                    if ($rootPassword) {
                        $this->database->mysql_root_password = str($rootPassword)->after('MYSQL_ROOT_PASSWORD=')->value();
                    }

                    $db = $envs->filter(function ($env) {
                        return str($env)->startsWith('MYSQL_DATABASE=');
                    })->first();

                    if ($db) {
                        $databasesToBackup = str($db)->after('MYSQL_DATABASE=')->value();
                    } else {
                        throw new \Exception('MYSQL_DATABASE not found');
                    }
                } elseif (str($databaseType)->contains('mariadb')) {
                    $this->container_name = "{$this->database->name}-$serviceUuid";
                    $this->directory_name = $serviceName.'-'.$this->container_name;
                    $commands[] = "docker exec $this->container_name env";
                    $envs = instant_remote_process($commands, $this->server);
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

                    $db = $envs->filter(function ($env) {
                        return str($env)->startsWith('MARIADB_DATABASE=');
                    })->first();

                    if ($db) {
                        $databasesToBackup = str($db)->after('MARIADB_DATABASE=')->value();
                    } else {
                        $db = $envs->filter(function ($env) {
                            return str($env)->startsWith('MYSQL_DATABASE=');
                        })->first();

                        if ($db) {
                            $databasesToBackup = str($db)->after('MYSQL_DATABASE=')->value();
                        } else {
                            throw new \Exception('MARIADB_DATABASE or MYSQL_DATABASE not found');
                        }
                    }
                } elseif (str($databaseType)->contains('mongo')) {
                    $databasesToBackup = ['*'];
                    $this->container_name = "{$this->database->name}-$serviceUuid";
                    $this->directory_name = $serviceName.'-'.$this->container_name;

                    // Try to extract MongoDB credentials from environment variables
                    try {
                        $commands = [];
                        $commands[] = "docker exec $this->container_name env | grep MONGO_INITDB_";
                        $envs = instant_remote_process($commands, $this->server);

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
                $databasesToBackup = data_get($this->backup, 'databases_to_backup');
            }
            if (blank($databasesToBackup)) {
                if (str($databaseType)->contains('postgres')) {
                    $databasesToBackup = [$this->database->postgres_db];
                } elseif (str($databaseType)->contains('mongo')) {
                    $databasesToBackup = ['*'];
                } elseif (str($databaseType)->contains('mysql')) {
                    $databasesToBackup = [$this->database->mysql_database];
                } elseif (str($databaseType)->contains('mariadb')) {
                    $databasesToBackup = [$this->database->mariadb_database];
                } else {
                    return;
                }
            } else {
                if (str($databaseType)->contains('postgres')) {
                    // Format: db1,db2,db3
                    $databasesToBackup = explode(',', $databasesToBackup);
                    $databasesToBackup = array_map('trim', $databasesToBackup);
                } elseif (str($databaseType)->contains('mongo')) {
                    // Format: db1:collection1,collection2|db2:collection3,collection4
                    // Only explode if it's a string, not if it's already an array
                    if (is_string($databasesToBackup)) {
                        $databasesToBackup = explode('|', $databasesToBackup);
                        $databasesToBackup = array_map('trim', $databasesToBackup);
                    }
                } elseif (str($databaseType)->contains('mysql')) {
                    // Format: db1,db2,db3
                    $databasesToBackup = explode(',', $databasesToBackup);
                    $databasesToBackup = array_map('trim', $databasesToBackup);
                } elseif (str($databaseType)->contains('mariadb')) {
                    // Format: db1,db2,db3
                    $databasesToBackup = explode(',', $databasesToBackup);
                    $databasesToBackup = array_map('trim', $databasesToBackup);
                } else {
                    return;
                }
            }
            $this->backup_dir = backup_dir().'/databases/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->directory_name;
            if ($this->database->name === 'coolify-db') {
                $databasesToBackup = ['coolify'];
                $this->directory_name = $this->container_name = 'coolify-db';
                $ip = Str::slug($this->server->ip);
                $this->backup_dir = backup_dir().'/coolify'."/coolify-db-$ip";
            }
            foreach ($databasesToBackup as $database) {
                // Generate unique UUID for each database backup execution
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

                // Step 1: Create local backup
                try {
                    $backupResult = ['uses_native_s3' => false, 'backup_size' => 0, 's3_uploaded' => false];

                    if (str($databaseType)->contains('postgres')) {
                        // For pgBackRest, use repository directory instead of single file
                        if ($this->backup->isPgBackRest()) {
                            $stanzaName = $this->backup->getStanzaName();
                            $backupType = $this->backup->getPgBackRestType();
                            $this->backup_file = "/.pgbackrest/stanza-$stanzaName-$backupType-".Carbon::now()->timestamp;
                        } else {
                            $this->backup_file = "/pg-dump-$database-".Carbon::now()->timestamp.'.dmp';
                            if ($this->backup->dump_all) {
                                $this->backup_file = '/pg-dump-all-'.Carbon::now()->timestamp.'.gz';
                            }
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        $backupResult = $this->backup_standalone_postgresql($database);
                    } elseif (str($databaseType)->contains('mongo')) {
                        if ($database === '*') {
                            $database = 'all';
                            $databaseName = 'all';
                        } else {
                            if (str($database)->contains(':')) {
                                $databaseName = str($database)->before(':');
                            } else {
                                $databaseName = $database;
                            }
                        }
                        $this->backup_file = "/mongo-dump-$databaseName-".Carbon::now()->timestamp.'.tar.gz';
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $databaseName,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        $this->backup_standalone_mongodb($database);
                    } elseif (str($databaseType)->contains('mysql')) {
                        $this->backup_file = "/mysql-dump-$database-".Carbon::now()->timestamp.'.dmp';
                        if ($this->backup->dump_all) {
                            $this->backup_file = '/mysql-dump-all-'.Carbon::now()->timestamp.'.gz';
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        $this->backup_standalone_mysql($database);
                    } elseif (str($databaseType)->contains('mariadb')) {
                        $this->backup_file = "/mariadb-dump-$database-".Carbon::now()->timestamp.'.dmp';
                        if ($this->backup->dump_all) {
                            $this->backup_file = '/mariadb-dump-all-'.Carbon::now()->timestamp.'.gz';
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        $this->backup_standalone_mariadb($database);
                    } else {
                        throw new \Exception('Unsupported database type');
                    }

                    // For pgBackRest with native S3, use size from backup info
                    if ($backupResult['uses_native_s3'] && $backupResult['backup_size'] > 0) {
                        $size = $backupResult['backup_size'];
                        $this->s3_uploaded = true;
                        $localBackupSucceeded = true;
                    } else {
                        // Standard backup or pgBackRest with local repository
                        $size = $this->calculate_size();

                        // Verify local backup succeeded
                        if ($size > 0) {
                            $localBackupSucceeded = true;
                        } else {
                            throw new \Exception('Local backup file is empty or was not created');
                        }
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
                    $this->team?->notify(new BackupFailed($this->backup, $this->database, $this->error_output ?? $this->backup_output ?? $e->getMessage(), $database));

                    continue;
                }

                // Step 2: Upload to S3 if enabled (independent of local backup)
                $localStorageDeleted = false;
                if ($this->backup->save_s3 && $localBackupSucceeded && !$backupResult['uses_native_s3']) {
                    // Only upload manually if not using pgBackRest native S3
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
                } elseif ($backupResult['uses_native_s3']) {
                    // pgBackRest native S3 - backup is already in S3, mark as uploaded
                    $this->s3_uploaded = true;
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
                        $this->team->notify(new BackupSuccessWithS3Warning($this->backup, $this->database, $database, $s3UploadError));
                    } else {
                        $this->team->notify(new BackupSuccess($this->backup, $this->database, $database));
                    }
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

    private function backup_standalone_mongodb(string $databaseWithCollections): void
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
            if ($databaseWithCollections === 'all') {
                $commands[] = 'mkdir -p '.$this->backup_dir;
                if (str($this->database->image)->startsWith('mongo:4')) {
                    $commands[] = "docker exec $this->container_name mongodump --uri=\"$url\" --gzip --archive > $this->backup_location";
                } else {
                    $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=\"$url\" --gzip --archive > $this->backup_location";
                }
            } else {
                if (str($databaseWithCollections)->contains(':')) {
                    $databaseName = str($databaseWithCollections)->before(':');
                    $collectionsToExclude = str($databaseWithCollections)->after(':')->explode(',');
                } else {
                    $databaseName = $databaseWithCollections;
                    $collectionsToExclude = collect();
                }
                $commands[] = 'mkdir -p '.$this->backup_dir;
                if ($collectionsToExclude->count() === 0) {
                    if (str($this->database->image)->startsWith('mongo:4')) {
                        $commands[] = "docker exec $this->container_name mongodump --uri=\"$url\" --gzip --archive > $this->backup_location";
                    } else {
                        $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=\"$url\" --db $databaseName --gzip --archive > $this->backup_location";
                    }
                } else {
                    if (str($this->database->image)->startsWith('mongo:4')) {
                        $commands[] = "docker exec $this->container_name mongodump --uri=$url --gzip --excludeCollection ".$collectionsToExclude->implode(' --excludeCollection ')." --archive > $this->backup_location";
                    } else {
                        $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=\"$url\" --db $databaseName --gzip --excludeCollection ".$collectionsToExclude->implode(' --excludeCollection ')." --archive > $this->backup_location";
                    }
                }
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_postgresql(string $database): array
    {
        try {
            // Check if pgBackRest is enabled for this backup
            if ($this->backup->isPgBackRest()) {
                return $this->backup_pgbackrest_postgresql($database);
            }

            $commands[] = 'mkdir -p '.$this->backup_dir;
            $backupCommand = 'docker exec';
            if ($this->postgres_password) {
                $backupCommand .= " -e PGPASSWORD=\"{$this->postgres_password}\"";
            }
            if ($this->backup->dump_all) {
                $backupCommand .= " $this->container_name pg_dumpall --username {$this->database->postgres_user} | gzip > $this->backup_location";
            } else {
                $backupCommand .= " $this->container_name pg_dump --format=custom --no-acl --no-owner --username {$this->database->postgres_user} $database > $this->backup_location";
            }

            $commands[] = $backupCommand;
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }

            return ['uses_native_s3' => false];

        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

     private function backup_pgbackrest_postgresql(string $database): array
    {
        try {
            $commands = [];
            $stanzaName = $this->backup->getStanzaName();
            $backupType = $this->backup->getPgBackRestType();
            $processMax = $this->backup->pgbackrest_process_max ?? 1;
            $usesNativeS3 = $this->s3 !== null;

            // Use versioned pgBackRest image for stability
            $pgbackrestImage = 'woblerr/pgbackrest:2.48';

            // Create pgBackRest configuration directory
            $pgbackrestConfigDir = escapeshellarg($this->backup_dir.'/.pgbackrest');
            $commands[] = "mkdir -p $pgbackrestConfigDir";

            // Generate pgBackRest configuration
            $config = $this->generate_pgbackrest_config($stanzaName, $database);
            $configPath = trim($pgbackrestConfigDir, "'").'/'.'pgbackrest.conf';
            $quotedConfigPath = escapeshellarg($configPath);

            // Write config file to server (safely using base64)
            $escapedConfig = base64_encode($config);
            $commands[] = "echo ".escapeshellarg($escapedConfig)." | base64 -d > $quotedConfigPath";

            // Get PostgreSQL container's data directory
            $containerName = escapeshellarg($this->container_name);
            $pgDataDir = instant_remote_process(["docker inspect $containerName --format='{{range .Mounts}}{{if eq .Destination \"/var/lib/postgresql/data\"}}{{.Source}}{{end}}{{end}}'"], $this->server, false);
            $pgDataDir = trim($pgDataDir);

            if (empty($pgDataDir)) {
                throw new \Exception('Could not determine PostgreSQL data directory');
            }

            $quotedPgDataDir = escapeshellarg($pgDataDir);
            $quotedBackupDir = escapeshellarg($this->backup_dir);
            $quotedStanzaName = escapeshellarg($stanzaName);
            $quotedBackupType = escapeshellarg($backupType);

            // S3 credentials are now safely stored in the config file (generate_pgbackrest_config)            

            // Check if stanza exists, create if not
            $stanzaCheckCommand = "docker run --rm ";
            $stanzaCheckCommand .= "-v $quotedConfigPath:/etc/pgbackrest/pgbackrest.conf:ro ";
            $stanzaCheckCommand .= "-v $quotedPgDataDir:/var/lib/postgresql/data:ro ";
            if ($this->s3) {
                $stanzaCheckCommand .= "-v $quotedBackupDir:/var/lib/pgbackrest:ro ";
            } else {
                $stanzaCheckCommand .= "-v $quotedBackupDir:/var/lib/pgbackrest:rw ";
            }
            $stanzaCheckCommand .= "$pgbackrestImage pgbackrest info --stanza=$quotedStanzaName 2>&1";

            $stanzaInfo = instant_remote_process([$stanzaCheckCommand], $this->server, false);

            if (str_contains($stanzaInfo, 'does not exist') || str_contains($stanzaInfo, 'unable to find')) {
                // Initialize stanza
                $this->add_to_backup_output("Initializing pgBackRest stanza: $stanzaName");

                $stanzaCreateCommand = "docker run --rm ";
                $stanzaCreateCommand .= "-v $quotedConfigPath:/etc/pgbackrest/pgbackrest.conf:ro ";
                $stanzaCreateCommand .= "-v $quotedPgDataDir:/var/lib/postgresql/data:rw ";
                $stanzaCreateCommand .= "-v $quotedBackupDir:/var/lib/pgbackrest:rw ";
                $stanzaCreateCommand .= "$pgbackrestImage ";
                $stanzaCreateCommand .= "pgbackrest stanza-create --stanza=$quotedStanzaName --log-level-console=info";

                $commands[] = $stanzaCreateCommand;
                $stanzaOutput = instant_remote_process([$stanzaCreateCommand], $this->server);
                $this->add_to_backup_output($stanzaOutput);
            }

            // Perform backup
            $this->add_to_backup_output("Starting pgBackRest $backupType backup for stanza: $stanzaName");

            $backupCommand = "docker run --rm ";
            $backupCommand .= "-v $quotedConfigPath:/etc/pgbackrest/pgbackrest.conf:ro ";
            $backupCommand .= "-v $quotedPgDataDir:/var/lib/postgresql/data:rw ";
            $backupCommand .= "-v $quotedBackupDir:/var/lib/pgbackrest:rw ";
            $backupCommand .= "$pgbackrestImage ";
            $backupCommand .= "pgbackrest backup --stanza=$quotedStanzaName --type=$quotedBackupType ";
            $backupCommand .= '--process-max='.escapeshellarg((string) $processMax).' --log-level-console=info';

            $commands[] = $backupCommand;
            $backupOutput = instant_remote_process($commands, $this->server);
            $this->add_to_backup_output($backupOutput);

            // Get backup info to verify success
            $infoCommand = "docker run --rm ";
            $infoCommand .= "-v $quotedConfigPath:/etc/pgbackrest/pgbackrest.conf:ro ";
            $infoCommand .= "-v $quotedBackupDir:/var/lib/pgbackrest:ro ";
            $infoCommand .= "$pgbackrestImage pgbackrest info --stanza=$quotedStanzaName --output=json";

            $infoOutput = instant_remote_process([$infoCommand], $this->server, false);

            // Store backup info in output
            $this->add_to_backup_output("\n=== Backup Info ===\n".$infoOutput);

            // Extract backup size from info JSON
            $backupSize = 0;
            try {
                $infoData = json_decode($infoOutput, true);
                if (isset($infoData[0]['backup']) && is_array($infoData[0]['backup'])) {
                    $lastBackup = end($infoData[0]['backup']);
                    if (isset($lastBackup['info']['size'])) {
                        $backupSize = $lastBackup['info']['size'];
                    }
                }
            } catch (\Throwable $e) {
                // Failed to parse JSON, size will remain 0
                $this->add_to_backup_output("\nWarning: Could not extract backup size from info output");
            }

            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }

            return [
                'uses_native_s3' => $usesNativeS3,
                'backup_size' => $backupSize,
                's3_uploaded' => $usesNativeS3,
            ];
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function generate_pgbackrest_config(string $stanzaName, string $database): string
    {
        $config = "[global]\n";
        $config .= "log-level-console=info\n";
        $config .= "log-level-file=info\n";
        $config .= "process-max={$this->backup->pgbackrest_process_max}\n";

        // Retention settings
        if ($this->backup->pgbackrest_retention_full) {
            $config .= "repo1-retention-full={$this->backup->pgbackrest_retention_full}\n";
        }

        if ($this->backup->pgbackrest_retention_diff) {
            $config .= "repo1-retention-diff={$this->backup->pgbackrest_retention_diff}\n";
        }

        // Repository configuration
        if ($this->s3) {
            // S3 repository with credentials in config file (secure)
            $config .= "repo1-type=s3\n";
            $config .= "repo1-s3-bucket={$this->s3->bucket}\n";
            $config .= "repo1-s3-endpoint={$this->s3->endpoint}\n";
            $config .= "repo1-s3-region={$this->s3->region}\n";
            $config .= "repo1-path=/pgbackrest/{$stanzaName}\n";

            // Store credentials directly in config file (more secure than env vars)
            $config .= "repo1-s3-key={$this->s3->key}\n";
            $config .= "repo1-s3-key-secret={$this->s3->secret}\n";
        } else {
            // Local repository
            $config .= "repo1-path=/var/lib/pgbackrest\n";
        }

        // Block incremental backup (if enabled and supported)
        if ($this->backup->pgbackrest_block_incremental) {
            $config .= "repo1-block=y\n";
        }

        // Stanza configuration
        $config .= "\n[{$stanzaName}]\n";
        $config .= "pg1-path=/var/lib/postgresql/data\n";

        if ($this->postgres_password) {
            $config .= "pg1-port=5432\n";
        }

        return $config;
    }

    private function backup_standalone_mysql(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            if ($this->backup->dump_all) {
                $commands[] = "docker exec $this->container_name mysqldump -u root -p\"{$this->database->mysql_root_password}\" --all-databases --single-transaction --quick --lock-tables=false --compress | gzip > $this->backup_location";
            } else {
                $commands[] = "docker exec $this->container_name mysqldump -u root -p\"{$this->database->mysql_root_password}\" $database > $this->backup_location";
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mysql(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            if ($this->backup->dump_all) {
                $commands[] = "docker exec $this->container_name mysqldump -u root -p\"{$this->database->mysql_root_password}\" --all-databases --single-transaction --quick --lock-tables=false --compress | gzip > $this->backup_location";
            } else {
                $commands[] = "docker exec $this->container_name mysqldump -u root -p\"{$this->database->mysql_root_password}\" $database > $this->backup_location";
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (\Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mariadb(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            if ($this->backup->dump_all) {
                $commands[] = "docker exec $this->container_name mariadb-dump -u root -p\"{$this->database->mariadb_root_password}\" --all-databases --single-transaction --quick --lock-tables=false --compress > $this->backup_location";
            } else {
                $commands[] = "docker exec $this->container_name mariadb-dump -u root -p\"{$this->database->mariadb_root_password}\" $database > $this->backup_location";
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
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
        return instant_remote_process(["du -b $this->backup_location | cut -f1"], $this->server, false);
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

            $containerExists = instant_remote_process(["docker ps -a -q -f name=backup-of-{$this->backup_log_uuid}"], $this->server, false);
            if (filled($containerExists)) {
                instant_remote_process(["docker rm -f backup-of-{$this->backup_log_uuid}"], $this->server, false);
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
            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc alias set temporary {$endpoint} {$key} \"{$secret}\"";
            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc cp $this->backup_location temporary/$bucket{$this->backup_dir}/";
            instant_remote_process($commands, $this->server);

            $this->s3_uploaded = true;
        } catch (\Throwable $e) {
            $this->s3_uploaded = false;
            $this->add_to_error_output($e->getMessage());
            throw $e;
        } finally {
            $command = "docker rm -f backup-of-{$this->backup_log_uuid}";
            instant_remote_process([$command], $this->server);
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
            $databaseName = $log?->database_name ?? 'unknown';
            $output = $this->backup_output ?? $exception?->getMessage() ?? 'Unknown error';
            $this->team->notify(new BackupFailed($this->backup, $this->database, $output, $databaseName));
        }
    }
}
