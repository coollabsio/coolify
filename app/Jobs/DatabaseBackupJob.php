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
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    public ?string $postgres_data_path = null;

    public ?string $postgres_pgdata_path = null;

    public ?string $postgres_uid = null;

    public ?string $postgres_gid = null;

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

    public function middleware(): array
    {
        $expireAfter = ($this->backup->timeout ?? 3600) + 300;

        return [(new WithoutOverlapping('database-backup-'.$this->backup->id))->expireAfter($expireAfter)->dontRelease()];
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
            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
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

            $this->markStaleExecutionsAsFailed();

            BackupCreated::dispatch($this->team->id);

            $status = str(data_get($this->database, 'status'));
            if (! $status->startsWith('running') && $this->database->id !== 0) {
                Log::info('DatabaseBackupJob skipped: database not running', [
                    'backup_id' => $this->backup->id,
                    'database_id' => $this->database->id,
                    'status' => (string) $status,
                ]);

                return;
            }
            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
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

                    $db = $envs->filter(function ($env) {
                        return str($env)->startsWith('POSTGRES_DB=');
                    })->first();

                    if ($db) {
                        $databasesToBackup = str($db)->after('POSTGRES_DB=')->value();
                    } else {
                        $databasesToBackup = $this->database->postgres_user;
                    }
                    $this->database->postgres_db = $databasesToBackup;
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

                    } catch (Throwable $e) {
                        // Continue without env vars - will be handled in backup_standalone_mongodb method
                    }
                }
            } else {
                $databaseName = str($this->database->name)->slug()->value();
                $this->container_name = $this->database->uuid;
                $this->directory_name = $databaseName.'-'.$this->container_name;
                $databaseType = $this->database->type();
                if (str($databaseType)->contains('postgres')) {
                    $this->postgres_password = data_get($this->database, 'postgres_password');
                }
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
            $usesPgBackRest = $this->usesPgBackRest($databaseType);
            if ($usesPgBackRest) {
                $databasesToBackup = ['postgres-cluster'];
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
                    if (str($databaseType)->contains('postgres')) {
                        if ($usesPgBackRest) {
                            $stanza = $this->pgBackRestStanzaName();
                            $this->backup_file = '/pgbackrest-'.$stanza.'-'.Carbon::now()->timestamp;
                            $this->backup_location = $this->pgBackRestRepositoryKeyPrefix($stanza);
                        } else {
                            $this->backup_file = "/pg-dump-$database-".Carbon::now()->timestamp.'.dmp';
                            if ($this->backup->dump_all) {
                                $this->backup_file = '/pg-dump-all-'.Carbon::now()->timestamp.'.gz';
                            }
                            $this->backup_location = $this->backup_dir.$this->backup_file;
                        }
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        if ($usesPgBackRest) {
                            $this->backup_standalone_postgresql_pgbackrest($stanza);
                        } else {
                            $this->backup_standalone_postgresql($database);
                        }
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

                    $size = $usesPgBackRest ? $this->calculate_pgbackrest_size() : $this->calculate_size();

                    // Verify local backup succeeded
                    if ($usesPgBackRest || $size > 0) {
                        $localBackupSucceeded = true;
                    } else {
                        throw new \Exception('Local backup file is empty or was not created');
                    }
                } catch (Throwable $e) {
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
                    try {
                        $this->team?->notify(new BackupFailed($this->backup, $this->database, $this->error_output ?? $this->backup_output ?? $e->getMessage(), $database));
                    } catch (Throwable $notifyException) {
                        Log::channel('scheduled-errors')->warning('Failed to send backup failure notification', [
                            'backup_id' => $this->backup->uuid,
                            'database' => $database,
                            'error' => $notifyException->getMessage(),
                        ]);
                    }

                    continue;
                }

                // Step 2: Upload to S3 if enabled (independent of local backup)
                $localStorageDeleted = $usesPgBackRest;
                if (! $usesPgBackRest && $this->backup->save_s3 && $localBackupSucceeded) {
                    try {
                        $this->upload_to_s3();

                        // If local backup is disabled, delete the local file immediately after S3 upload
                        if ($this->backup->disable_local_backup) {
                            deleteBackupsLocally($this->backup_location, $this->server);
                            $localStorageDeleted = true;
                        }
                    } catch (Throwable $e) {
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

                    // Send appropriate notification (wrapped in try-catch so notification
                    // failures never affect backup status — see GitHub issue #9088)
                    try {
                        if ($s3UploadError) {
                            $this->team->notify(new BackupSuccessWithS3Warning($this->backup, $this->database, $database, $s3UploadError));
                        } else {
                            $this->team->notify(new BackupSuccess($this->backup, $this->database, $database));
                        }
                    } catch (Throwable $e) {
                        Log::channel('scheduled-errors')->warning('Failed to send backup success notification', [
                            'backup_id' => $this->backup->uuid,
                            'database' => $database,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
            if ($this->backup_log && $this->backup_log->status === 'success' && ! $usesPgBackRest) {
                removeOldBackups($this->backup);
            }
        } catch (Throwable $e) {
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
                    // URL-encode credentials to prevent URI injection
                    $encodedUser = rawurlencode($this->mongo_root_username);
                    $encodedPass = rawurlencode($this->mongo_root_password);
                    $url = "mongodb://{$encodedUser}:{$encodedPass}@{$this->container_name}:27017";
                } else {
                    // If no environment variables are available, throw an exception
                    throw new \Exception('MongoDB credentials not found. Ensure MONGO_INITDB_ROOT_USERNAME and MONGO_INITDB_ROOT_PASSWORD environment variables are available in the container.');
                }
            }
            Log::info('MongoDB backup URL configured', ['has_url' => filled($url), 'using_env_vars' => blank($this->database->internal_db_url)]);
            $escapedUrl = escapeshellarg($url);
            if ($databaseWithCollections === 'all') {
                $commands[] = 'mkdir -p '.$this->backup_dir;
                if (str($this->database->image)->startsWith('mongo:4')) {
                    $commands[] = "docker exec $this->container_name mongodump --uri=$escapedUrl --gzip --archive > $this->backup_location";
                } else {
                    $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$escapedUrl --gzip --archive > $this->backup_location";
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

                // Validate and escape database name to prevent command injection
                validateShellSafePath($databaseName, 'database name');
                $escapedDatabaseName = escapeshellarg($databaseName);

                if ($collectionsToExclude->count() === 0) {
                    if (str($this->database->image)->startsWith('mongo:4')) {
                        $commands[] = "docker exec $this->container_name mongodump --uri=$escapedUrl --gzip --archive > $this->backup_location";
                    } else {
                        $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$escapedUrl --db $escapedDatabaseName --gzip --archive > $this->backup_location";
                    }
                } else {
                    // Validate and escape each collection name
                    $escapedCollections = $collectionsToExclude->map(function ($collection) {
                        $collection = trim($collection);
                        validateShellSafePath($collection, 'collection name');

                        return escapeshellarg($collection);
                    });

                    if (str($this->database->image)->startsWith('mongo:4')) {
                        $commands[] = "docker exec $this->container_name mongodump --uri=$escapedUrl --gzip --excludeCollection ".$escapedCollections->implode(' --excludeCollection ')." --archive > $this->backup_location";
                    } else {
                        $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$escapedUrl --db $escapedDatabaseName --gzip --excludeCollection ".$escapedCollections->implode(' --excludeCollection ')." --archive > $this->backup_location";
                    }
                }
            }
            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_postgresql(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $backupCommand = 'docker exec';
            if ($this->postgres_password) {
                $backupCommand .= ' -e PGPASSWORD='.escapeshellarg($this->postgres_password);
            }
            $escapedUsername = escapeshellarg($this->database->postgres_user);
            if ($this->backup->dump_all) {
                $backupCommand .= " $this->container_name pg_dumpall --username $escapedUsername | gzip > $this->backup_location";
            } else {
                // Validate and escape database name to prevent command injection
                validateShellSafePath($database, 'database name');
                $escapedDatabase = escapeshellarg($database);
                $backupCommand .= " $this->container_name pg_dump --format=custom --no-acl --no-owner --username $escapedUsername $escapedDatabase > $this->backup_location";
            }

            $commands[] = $backupCommand;
            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_postgresql_pgbackrest(string $stanza): void
    {
        if (! $this->backup->save_s3) {
            throw new \Exception('pgBackRest backups require S3 storage because pgBackRest writes an incremental repository instead of a single local dump file.');
        }

        if (is_null($this->s3)) {
            throw new \Exception('S3 storage configuration is required for pgBackRest backups.');
        }

        $remoteEnvFile = null;
        try {
            $this->s3->testConnection(shouldSave: true);
            $this->postgres_data_path = $this->discoverPostgresDataPathOnHost();
            instant_remote_process(['mkdir -p '.escapeshellarg($this->backup_dir)], $this->server, true, false, $this->timeout, disableMultiplexing: true);

            $archiveCheckEnabled = $this->pgBackRestArchiveCheckEnabled();
            $requiresWalArchive = $this->pgBackRestRequiresWalArchive();
            if (! $archiveCheckEnabled && $requiresWalArchive) {
                throw new \Exception('pgBackRest WAL archive verification is required for this backup, but PostgreSQL is not configured with archive_mode=on and archive_command using pgbackrest archive-push. Configure WAL archiving or disable WAL archive verification for this schedule to run pgBackRest with --archive-check=n.');
            }
            $archiveCheckWarning = $archiveCheckEnabled ? null : 'Warning: PostgreSQL WAL archiving is not configured for pgBackRest on this database, so Coolify ran pgBackRest with --archive-check=n. Configure archive_mode=on and archive_command=pgbackrest archive-push for WAL-verified/PITR backups.';
            $remoteEnvFile = $this->pgBackRestUploadEnvFile();

            $commands = [];
            $commands[] = 'docker rm -f '.escapeshellarg('pgbackrest-of-'.$this->backup_log_uuid).' >/dev/null 2>&1 || true';

            $stanzaCreate = $this->pgBackRestDockerCommand($stanza, 'stanza-create', [], $remoteEnvFile);
            $stanzaInfo = $this->pgBackRestDockerCommand($stanza, 'info', [], $remoteEnvFile);
            $backupOptions = [
                '--type='.escapeshellarg($this->pgBackRestBackupType()),
                '--start-fast=y',
                '--expire-auto=n',
            ];
            if (! $archiveCheckEnabled) {
                $backupOptions[] = '--archive-check=n';
            }
            $backup = $this->pgBackRestDockerCommand($stanza, 'backup', $backupOptions, $remoteEnvFile);

            $commands[] = "($stanzaCreate) || ($stanzaInfo)";
            if ($archiveCheckEnabled) {
                $commands[] = $this->pgBackRestDockerCommand($stanza, 'check', [], $remoteEnvFile);
            }
            $commands[] = $backup;

            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);

            $expireWarning = null;
            if (! empty($this->pgBackRestRetentionOptions())) {
                try {
                    $expireOutput = instant_remote_process([$this->pgBackRestDockerCommand($stanza, 'expire', $this->pgBackRestRetentionOptions(), $remoteEnvFile)], $this->server, true, false, $this->timeout, disableMultiplexing: true);
                    $expireOutput = trim($expireOutput);
                    if ($expireOutput !== '') {
                        $this->backup_output = $this->backup_output === '' ? $expireOutput : $this->backup_output."\n".$expireOutput;
                    }
                } catch (Throwable $expireException) {
                    $expireWarning = 'Warning: pgBackRest backup completed but retention expire failed: '.$expireException->getMessage();
                    Log::channel('scheduled-errors')->warning('pgBackRest expire failed after successful backup', [
                        'backup_id' => $this->backup->uuid,
                        'error' => $expireException->getMessage(),
                    ]);
                }
            }

            foreach ([$archiveCheckWarning, $expireWarning] as $warning) {
                if ($warning) {
                    $this->backup_output = $this->backup_output === '' ? $warning : $warning."\n\n".$this->backup_output;
                }
            }
            if ($this->backup_output === '') {
                $this->backup_output = 'pgBackRest backup completed successfully.';
            }
            $this->s3_uploaded = true;
        } catch (Throwable $e) {
            $this->s3_uploaded = false;
            $this->add_to_error_output($e->getMessage());
            throw $e;
        } finally {
            if ($remoteEnvFile) {
                try {
                    $this->pgBackRestCleanupRemoteEnvFile($remoteEnvFile);
                } catch (Throwable $cleanupException) {
                    Log::channel('scheduled-errors')->warning('Unable to remove temporary pgBackRest environment file', [
                        'backup_id' => $this->backup->uuid,
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }
        }
    }

    private function usesPgBackRest(string $databaseType): bool
    {
        return str($databaseType)->contains('postgres') && data_get($this->backup, 'backup_method', 'dump') === 'pgbackrest';
    }

    private function pgBackRestStanzaName(): string
    {
        $stanza = Str::of('coolify-'.$this->backup->uuid)
            ->replaceMatches('/[^A-Za-z0-9_-]/', '-')
            ->lower()
            ->limit(63, '')
            ->value();

        validateShellSafePath($stanza, 'pgBackRest stanza');

        return $stanza;
    }

    private function pgBackRestBackupType(): string
    {
        $type = data_get($this->backup, 'pgbackrest_backup_type', 'incr');

        return in_array($type, ['full', 'diff', 'incr'], true) ? $type : 'incr';
    }

    private function pgBackRestRepositoryPath(string $stanza): string
    {
        return '/'.trim($this->backup_dir, '/').'/pgbackrest/'.$stanza;
    }

    private function pgBackRestRepositoryKeyPrefix(string $stanza): string
    {
        return trim($this->pgBackRestRepositoryPath($stanza), '/').'/';
    }

    private function pgBackRestDockerCommand(string $stanza, string $command, array $extraOptions, string $envFilePath): string
    {
        $network = data_get($this->backup, 'database_type') === ServiceDatabase::class
            ? $this->database->service->destination->network
            : $this->database->destination->network;

        $dockerName = escapeshellarg('pgbackrest-of-'.$this->backup_log_uuid);
        $safeNetwork = escapeshellarg($network);
        $image = escapeshellarg(config('constants.coolify.pgbackrest_image'));
        $pgDataPath = $this->postgres_pgdata_path ?: '/var/lib/postgresql/data';
        $dataVolume = escapeshellarg($this->postgres_data_path.':'.$pgDataPath.':ro');
        $envFile = ' --env-file '.escapeshellarg($envFilePath);

        $options = array_merge($this->pgBackRestBaseOptions($stanza), $extraOptions);

        return "docker run --rm --network {$safeNetwork} --name {$dockerName}{$envFile} -v {$dataVolume} {$image} pgbackrest ".implode(' ', $options).' '.$command;
    }

    private function pgBackRestUploadEnvFile(): string
    {
        $remoteEnvDirectory = $this->pgBackRestRemoteEnvDirectory();
        $remoteEnvFile = $this->pgBackRestRemoteEnvFilePath();

        $localEnvDirectory = storage_path('app/pgbackrest-env');
        if (! is_dir($localEnvDirectory) && ! mkdir($localEnvDirectory, 0700, true) && ! is_dir($localEnvDirectory)) {
            throw new \Exception('Unable to create local temporary directory for pgBackRest environment file.');
        }

        $localEnvFile = tempnam($localEnvDirectory, 'pgbackrest-');
        if ($localEnvFile === false) {
            throw new \Exception('Unable to create local temporary pgBackRest environment file.');
        }

        try {
            $bytesWritten = file_put_contents($localEnvFile, $this->pgBackRestEnvFileContents());
            if ($bytesWritten === false) {
                throw new \Exception('Unable to write local temporary pgBackRest environment file.');
            }
            chmod($localEnvFile, 0600);
            instant_remote_process(['mkdir -m 700 '.escapeshellarg($remoteEnvDirectory)], $this->server, true, true, $this->timeout, disableMultiplexing: true);
            instant_scp($localEnvFile, $remoteEnvFile, $this->server);
            instant_remote_process(['chmod 600 '.escapeshellarg($remoteEnvFile)], $this->server, true, true, $this->timeout, disableMultiplexing: true);
        } catch (Throwable $e) {
            try {
                $this->pgBackRestCleanupRemoteEnvFile($remoteEnvFile);
            } catch (Throwable) {
            }
            throw $e;
        } finally {
            if (file_exists($localEnvFile)) {
                unlink($localEnvFile);
            }
        }

        return $remoteEnvFile;
    }

    private function pgBackRestRemoteEnvDirectory(): string
    {
        if (blank($this->backup_log_uuid)) {
            throw new \Exception('Missing pgBackRest backup execution UUID for temporary environment file.');
        }

        $directory = '/tmp/coolify-pgbackrest-'.$this->backup_log_uuid;
        validateShellSafePath($directory, 'pgBackRest environment directory path');

        return $directory;
    }

    private function pgBackRestRemoteEnvFilePath(): string
    {
        $path = $this->pgBackRestRemoteEnvDirectory().'/pgbackrest.env';
        validateShellSafePath($path, 'pgBackRest environment file path');

        return $path;
    }

    private function pgBackRestCleanupRemoteEnvFile(string $remoteEnvFile): void
    {
        validateShellSafePath($remoteEnvFile, 'pgBackRest environment file path');
        $remoteEnvDirectory = dirname($remoteEnvFile);
        validateShellSafePath($remoteEnvDirectory, 'pgBackRest environment directory path');

        instant_remote_process([
            'rm -f '.escapeshellarg($remoteEnvFile).' && rmdir '.escapeshellarg($remoteEnvDirectory).' 2>/dev/null || true',
        ], $this->server, false, true, $this->timeout, disableMultiplexing: true);
    }

    private function pgBackRestEnvFileContents(): string
    {
        $lines = array_filter([
            $this->pgBackRestEnvFileLine('PGHOST', $this->container_name),
            $this->pgBackRestEnvFileLine('PGPORT', '5432'),
            $this->postgres_password ? $this->pgBackRestEnvFileLine('PGPASSWORD', $this->postgres_password) : null,
            filled($this->postgres_uid) && ctype_digit($this->postgres_uid) ? $this->pgBackRestEnvFileLine('BACKREST_UID', $this->postgres_uid) : null,
            filled($this->postgres_gid) && ctype_digit($this->postgres_gid) ? $this->pgBackRestEnvFileLine('BACKREST_GID', $this->postgres_gid) : null,
            $this->pgBackRestEnvFileLine('PGBACKREST_REPO1_S3_KEY', $this->s3->key),
            $this->pgBackRestEnvFileLine('PGBACKREST_REPO1_S3_KEY_SECRET', $this->s3->secret),
        ]);

        return implode("\n", $lines)."\n";
    }

    private function pgBackRestEnvFileLine(string $key, ?string $value): string
    {
        if (! preg_match('/^[A-Z0-9_]+$/', $key)) {
            throw new \Exception('Invalid pgBackRest environment variable name.');
        }

        $value = (string) $value;
        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new \Exception("pgBackRest environment value for {$key} contains unsupported control characters.");
        }

        return "{$key}={$value}";
    }

    private function pgBackRestBaseOptions(string $stanza): array
    {
        $database = data_get($this->database, 'postgres_db') ?: 'postgres';
        $username = data_get($this->database, 'postgres_user') ?: 'postgres';
        $pgDataPath = $this->postgres_pgdata_path ?: '/var/lib/postgresql/data';

        return [
            '--stanza='.escapeshellarg($stanza),
            '--pg1-path='.escapeshellarg($pgDataPath),
            '--pg1-user='.escapeshellarg($username),
            '--pg1-database='.escapeshellarg($database),
            '--repo1-type=s3',
            '--repo1-path='.escapeshellarg($this->pgBackRestRepositoryPath($stanza)),
            '--repo1-s3-bucket='.escapeshellarg($this->s3->bucket),
            '--repo1-s3-endpoint='.escapeshellarg($this->normalizedS3Endpoint()),
            '--repo1-s3-region='.escapeshellarg($this->s3->region ?: 'us-east-1'),
            '--repo1-s3-uri-style=path',
            '--log-level-console=info',
            '--process-max=2',
        ];
    }

    private function pgBackRestRetentionOptions(): array
    {
        $retentionAmount = (int) data_get($this->backup, 'database_backup_retention_amount_s3', 0);
        $retentionDays = (int) data_get($this->backup, 'database_backup_retention_days_s3', 0);

        if ($retentionAmount > 0) {
            return [
                '--repo1-retention-full='.escapeshellarg((string) $retentionAmount),
                '--repo1-retention-full-type=count',
            ];
        }

        if ($retentionDays > 0) {
            return [
                '--repo1-retention-full='.escapeshellarg((string) $retentionDays),
                '--repo1-retention-full-type=time',
            ];
        }

        return [];
    }

    private function pgBackRestArchiveCheckEnabled(): bool
    {
        $archiveMode = strtolower($this->postgresPsqlValue('SHOW archive_mode;'));
        $archiveCommand = strtolower($this->postgresPsqlValue('SHOW archive_command;'));

        return in_array($archiveMode, ['on', 'always'], true)
            && str_contains($archiveCommand, 'pgbackrest')
            && str_contains($archiveCommand, 'archive-push');
    }

    private function pgBackRestRequiresWalArchive(): bool
    {
        $value = data_get($this->backup, 'pgbackrest_require_wal_archive');

        return $value === null ? true : (bool) $value;
    }

    private function postgresPsqlValue(string $sql): string
    {
        return trim(instant_remote_process([$this->postgresPsqlCommand($sql).' 2>/dev/null || true'], $this->server, false, false, null, disableMultiplexing: true));
    }

    private function postgresPsqlCommand(string $sql): string
    {
        $container = escapeshellarg($this->container_name);
        $username = escapeshellarg(data_get($this->database, 'postgres_user') ?: 'postgres');
        $database = escapeshellarg(data_get($this->database, 'postgres_db') ?: 'postgres');
        $command = 'export PGPASSWORD="${PGPASSWORD:-${POSTGRES_PASSWORD:-}}"; exec psql -tA -v ON_ERROR_STOP=1 -U '.$username.' -d '.$database.' -c '.escapeshellarg($sql);

        return "docker exec {$container} sh -lc ".escapeshellarg($command);
    }

    private function discoverPostgresDataPathOnHost(): string
    {
        $container = escapeshellarg($this->container_name);
        $pgData = $this->postgresPsqlValue('SHOW data_directory;');
        if ($pgData === '') {
            $pgData = trim(instant_remote_process(["docker exec {$container} printenv PGDATA || true"], $this->server, false, false, null, disableMultiplexing: true));
        }
        if ($pgData === '') {
            $pgData = '/var/lib/postgresql/data';
        }
        $this->postgres_pgdata_path = rtrim($pgData, '/');
        $this->postgres_uid = trim(instant_remote_process(["docker exec {$container} sh -lc 'id -u postgres 2>/dev/null || id -u'"], $this->server, false, false, null, disableMultiplexing: true));
        $this->postgres_gid = trim(instant_remote_process(["docker exec {$container} sh -lc 'id -g postgres 2>/dev/null || id -g'"], $this->server, false, false, null, disableMultiplexing: true));

        $mounts = instant_remote_process(["docker inspect --format '{{range .Mounts}}{{printf \"%s|%s\\n\" .Destination .Source}}{{end}}' {$container}"], $this->server, true, false, null, disableMultiplexing: true);
        $bestDestination = null;
        $bestSource = null;

        foreach (explode("\n", trim($mounts)) as $line) {
            if (! str_contains($line, '|')) {
                continue;
            }
            [$destination, $source] = array_pad(explode('|', $line, 2), 2, null);
            $destination = rtrim((string) $destination, '/');
            $source = rtrim((string) $source, '/');
            if ($destination === '' || $source === '') {
                continue;
            }
            if ($this->postgres_pgdata_path === $destination || str_starts_with($this->postgres_pgdata_path, $destination.'/')) {
                if ($bestDestination === null || strlen($destination) > strlen($bestDestination)) {
                    $bestDestination = $destination;
                    $bestSource = $source;
                }
            }
        }

        if ($bestDestination === null || $bestSource === null) {
            throw new \Exception("Unable to find a Docker volume or bind mount for PostgreSQL PGDATA path {$pgData}. pgBackRest requires access to the database data directory.");
        }

        $relative = ltrim(substr($this->postgres_pgdata_path, strlen($bestDestination)), '/');

        return $bestSource.($relative ? '/'.$relative : '');
    }

    private function normalizedS3Endpoint(): string
    {
        $endpoint = (string) $this->s3->endpoint;
        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);

        if ($host) {
            return $host.($port ? ':'.$port : '');
        }

        return rtrim(preg_replace('#^https?://#', '', $endpoint), '/');
    }

    private function backup_standalone_mysql(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $escapedPassword = escapeshellarg($this->database->mysql_root_password);
            if ($this->backup->dump_all) {
                $commands[] = "docker exec $this->container_name mysqldump -u root -p$escapedPassword --all-databases --single-transaction --quick --lock-tables=false --compress | gzip > $this->backup_location";
            } else {
                // Validate and escape database name to prevent command injection
                validateShellSafePath($database, 'database name');
                $escapedDatabase = escapeshellarg($database);
                $commands[] = "docker exec $this->container_name mysqldump -u root -p$escapedPassword $escapedDatabase > $this->backup_location";
            }
            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mariadb(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $escapedPassword = escapeshellarg($this->database->mariadb_root_password);
            if ($this->backup->dump_all) {
                $commands[] = "docker exec $this->container_name mariadb-dump -u root -p$escapedPassword --all-databases --single-transaction --quick --lock-tables=false --compress > $this->backup_location";
            } else {
                // Validate and escape database name to prevent command injection
                validateShellSafePath($database, 'database name');
                $escapedDatabase = escapeshellarg($database);
                $commands[] = "docker exec $this->container_name mariadb-dump -u root -p$escapedPassword $escapedDatabase > $this->backup_location";
            }
            $this->backup_output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
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
        return instant_remote_process(['du -sb '.escapeshellarg($this->backup_location).' | cut -f1'], $this->server, false, false, null, disableMultiplexing: true);
    }

    private function calculate_pgbackrest_size(): int
    {
        return 0;
    }

    private function upload_to_s3(): void
    {
        if (is_null($this->s3)) {
            $this->backup->update([
                'save_s3' => false,
                's3_storage_id' => null,
            ]);

            throw new \Exception('S3 storage configuration is missing or has been deleted (S3 storage ID: '.($this->backup->s3_storage_id ?? 'null').'). S3 backup has been disabled for this schedule.');
        }

        try {
            $key = $this->s3->key;
            $secret = $this->s3->secret;
            // $region = $this->s3->region;
            $bucket = $this->s3->bucket;
            $endpoint = $this->s3->endpoint;
            $this->s3->testConnection(shouldSave: true);
            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $network = $this->database->service->destination->network;
            } else {
                $network = $this->database->destination->network;
            }
            $safeNetwork = escapeshellarg($network);

            $fullImageName = $this->getFullImageName();

            $containerExists = instant_remote_process(["docker ps -a -q -f name=backup-of-{$this->backup_log_uuid}"], $this->server, false, false, null, disableMultiplexing: true);
            if (filled($containerExists)) {
                instant_remote_process(["docker rm -f backup-of-{$this->backup_log_uuid}"], $this->server, false, false, null, disableMultiplexing: true);
            }

            if (isDev()) {
                if ($this->database->name === 'coolify-db') {
                    $backup_location_from = '/var/lib/docker/volumes/coolify_dev_backups_data/_data/coolify/coolify-db-'.$this->server->ip.$this->backup_file;
                    $commands[] = "docker run -d --network {$safeNetwork} --name backup-of-{$this->backup_log_uuid} --rm -v $backup_location_from:$this->backup_location:ro {$fullImageName}";
                } else {
                    $backup_location_from = '/var/lib/docker/volumes/coolify_dev_backups_data/_data/databases/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->directory_name.$this->backup_file;
                    $commands[] = "docker run -d --network {$safeNetwork} --name backup-of-{$this->backup_log_uuid} --rm -v $backup_location_from:$this->backup_location:ro {$fullImageName}";
                }
            } else {
                $commands[] = "docker run -d --network {$safeNetwork} --name backup-of-{$this->backup_log_uuid} --rm -v $this->backup_location:$this->backup_location:ro {$fullImageName}";
            }

            // Escape S3 credentials to prevent command injection
            $escapedEndpoint = escapeshellarg($endpoint);
            $escapedKey = escapeshellarg($key);
            $escapedSecret = escapeshellarg($secret);

            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc alias set temporary {$escapedEndpoint} {$escapedKey} {$escapedSecret}";
            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc cp $this->backup_location temporary/$bucket{$this->backup_dir}/";
            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);

            $this->s3_uploaded = true;
        } catch (Throwable $e) {
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

    private function markStaleExecutionsAsFailed(): void
    {
        try {
            $timeoutSeconds = ($this->backup->timeout ?? 3600) * 2;

            $staleExecutions = $this->backup->executions()
                ->where('status', 'running')
                ->where('created_at', '<', now()->subSeconds($timeoutSeconds))
                ->get();

            foreach ($staleExecutions as $execution) {
                $execution->update([
                    'status' => 'failed',
                    'message' => 'Marked as failed - backup execution exceeded maximum allowed time',
                    'finished_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('scheduled-errors')->warning('Failed to clean up stale backup executions', [
                'backup_id' => $this->backup->uuid,
                'error' => $e->getMessage(),
            ]);
        }
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
            // Don't overwrite a successful backup status — a post-backup error
            // (e.g. notification failure) should not retroactively mark the backup
            // as failed (see GitHub issue #9088)
            if ($log->status !== 'success') {
                $log->update([
                    'status' => 'failed',
                    'message' => 'Job permanently failed after '.$this->attempts().' attempts: '.($exception?->getMessage() ?? 'Unknown error'),
                    'size' => 0,
                    'filename' => null,
                    'finished_at' => Carbon::now(),
                ]);
            }
        }

        // Notify team about permanent failure (only if backup didn't already succeed)
        if ($this->team && $log?->status !== 'success') {
            $databaseName = $log?->database_name ?? 'unknown';
            $output = $this->backup_output ?? $exception?->getMessage() ?? 'Unknown error';
            try {
                $this->team->notify(new BackupFailed($this->backup, $this->database, $output, $databaseName));
            } catch (Throwable $e) {
                Log::channel('scheduled-errors')->warning('Failed to send backup permanent failure notification', [
                    'backup_id' => $this->backup->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
