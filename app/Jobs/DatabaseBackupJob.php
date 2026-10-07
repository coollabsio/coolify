<?php

namespace App\Jobs;

use App\Events\BackupCreated;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use App\Notifications\Database\BackupSuccessWithS3Warning;
use App\Rules\SafeWebhookUrl;
use App\Services\ScheduledJobDeliveryService;
use App\Support\BackupCompression;
use App\Support\ClickhouseBackupCommand;
use App\Support\DatabaseOperationReservation;
use App\Support\ResourceStartActivity;
use App\Support\ValidationPatterns;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class DatabaseBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Lets a backup that runs too long fail inside handle() before the worker times out.
     */
    public const WORKER_TIMEOUT_MARGIN_SECONDS = 120;

    public $maxExceptions = 1;

    public ?Team $team = null;

    public Server $server;

    public StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneClickhouse|StandaloneSqlite|ServiceDatabase $database;

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

    public $timeout = 3600 + self::WORKER_TIMEOUT_MARGIN_SECONDS;

    public ?string $backup_log_uuid = null;

    /**
     * Queued with the payload, so failed() finds this run's executions after a worker timeout.
     */
    public ?string $executionUuidPrefix = null;

    public function __construct(public ScheduledDatabaseBackup $backup, public ?string $occurrenceUuid = null)
    {
        $this->onQueue(crons_queue());
        $this->timeout = ($backup->timeout ?? 3600) + self::WORKER_TIMEOUT_MARGIN_SECONDS;
        $this->executionUuidPrefix = new_public_id(16);
    }

    public function middleware(): array
    {
        $expireAfter = ($this->backup->timeout ?? 3600) + 300;

        return [ScheduledJobDeliveryService::withoutOverlapping('database-backup-'.$this->backup->id, $this->occurrenceUuid)->expireAfter($expireAfter)->dontRelease()];
    }

    public function handle(): void
    {
        if ($this->occurrenceUuid && ! app(ScheduledJobDeliveryService::class)->claim($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid)) {
            return;
        }

        $failed = false;

        try {
            $databasesToBackup = null;

            $this->team = Team::find($this->backup->team_id);
            if (! $this->team) {
                $this->logSkippedRun('team_not_found');
                $this->backup->delete();

                return;
            }
            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $this->database = data_get($this->backup, 'database');
                $this->server = $this->database->service->server;
            } else {
                $this->database = data_get($this->backup, 'database');
                $this->server = $this->database->destination->server;
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
                $this->logSkippedRun('database_not_running', ['database_status' => (string) $status]);

                return;
            }
            if ($this->databaseOperationInProgress()) {
                $this->recordSkippedBackup('Skipped: a start, restart or import of this database was in progress. A backup taken now could contain a partly restored database.');
                $this->logSkippedRun('database_operation_in_progress');

                return;
            }
            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $databaseType = $this->database->databaseType();
                $serviceUuid = $this->database->service->uuid;
                $serviceName = str($this->database->service->name)->slug();
                $this->container_name = "{$this->database->name}-$serviceUuid";
                if (! ValidationPatterns::isValidContainerName($this->container_name)) {
                    throw new \Exception('Invalid database container name.');
                }
                // The container name is validated above, so it is a safe folder name. Keep the folder of earlier releases.
                $this->directory_name = $serviceName.'-'.$this->container_name;
                $escapedContainerName = escapeshellarg($this->container_name);
                if (str($databaseType)->contains('postgres')) {
                    $commands[] = "docker exec {$escapedContainerName} env | grep POSTGRES_";
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
                    $this->postgres_password = $envs->filter(function ($env) {
                        return str($env)->startsWith('POSTGRES_PASSWORD=');
                    })->first();
                    if ($this->postgres_password) {
                        $this->postgres_password = str($this->postgres_password)->after('POSTGRES_PASSWORD=')->value();
                    }
                } elseif (str($databaseType)->contains('mysql')) {
                    $commands[] = "docker exec {$escapedContainerName} env | grep MYSQL_";
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
                    $commands[] = "docker exec {$escapedContainerName} env";
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

                    // Try to extract MongoDB credentials from environment variables
                    try {
                        $commands = [];
                        $commands[] = "docker exec {$escapedContainerName} env | grep MONGO_INITDB_";
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
                } elseif ($this->database instanceof StandaloneClickhouse) {
                    $databasesToBackup = [$this->database->clickhouse_db];
                } elseif ($this->database instanceof StandaloneSqlite) {
                    $databasesToBackup = $this->database->sqlite_databases;
                } else {
                    $this->logSkippedRun('unsupported_database_type', ['service_database_type' => $databaseType]);

                    return;
                }
            }
            $databasesToBackup = $this->databasesToBackup($databaseType, $databasesToBackup);
            if ($databasesToBackup === []) {
                $this->logSkippedRun('no_databases_selected');

                return;
            }
            $this->backup_dir = backup_dir().'/databases/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->directory_name;
            if ($this->database->name === 'coolify-db') {
                $databasesToBackup = ['coolify'];
                $this->directory_name = $this->container_name = 'coolify-db';
                $ip = Str::slug($this->server->ip);
                $this->backup_dir = backup_dir().'/coolify'."/coolify-db-$ip";
            }
            foreach ($databasesToBackup as $database) {
                // Reset per-database state, so a failure before this database's execution exists
                // does not mark the previous database's execution failed or delete its backup file.
                $this->backup_log = null;
                $this->backup_location = null;
                $this->backup_output = null;
                $this->error_output = null;
                $this->s3_uploaded = false;

                // Generate unique UUID for each database backup execution
                $attempts = 0;
                do {
                    $this->backup_log_uuid = $this->executionUuidPrefix === null ? new_public_id() : $this->executionUuidPrefix.new_public_id(8);
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
                        $fileDatabaseName = $this->backupFilenamePart($database);
                        $this->backup_file = "/pg-dump-$fileDatabaseName-".Carbon::now()->timestamp."-{$this->backup_log_uuid}.dmp";
                        if ($this->backup->dump_all) {
                            $this->backup_file = '/pg-dump-all-'.Carbon::now()->timestamp."-{$this->backup_log_uuid}.gz";
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        BackupCreated::dispatch($this->team->id);
                        $this->backup_standalone_postgresql($database);
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
                        $fileDatabaseName = $this->backupFilenamePart($databaseName);
                        $this->backup_file = "/mongo-dump-$fileDatabaseName-".Carbon::now()->timestamp."-{$this->backup_log_uuid}.tar.gz";
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $databaseName,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        BackupCreated::dispatch($this->team->id);
                        $this->backup_standalone_mongodb($database);
                    } elseif (str($databaseType)->contains('mysql')) {
                        $fileDatabaseName = $this->backupFilenamePart($database);
                        $this->backup_file = "/mysql-dump-$fileDatabaseName-".Carbon::now()->timestamp."-{$this->backup_log_uuid}.dmp";
                        if ($this->backup->dump_all) {
                            $this->backup_file = '/mysql-dump-all-'.Carbon::now()->timestamp."-{$this->backup_log_uuid}.gz";
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        BackupCreated::dispatch($this->team->id);
                        $this->backup_standalone_mysql($database);
                    } elseif (str($databaseType)->contains('mariadb')) {
                        $fileDatabaseName = $this->backupFilenamePart($database);
                        $this->backup_file = "/mariadb-dump-$fileDatabaseName-".Carbon::now()->timestamp."-{$this->backup_log_uuid}.dmp";
                        if ($this->backup->dump_all) {
                            $this->backup_file = '/mariadb-dump-all-'.Carbon::now()->timestamp."-{$this->backup_log_uuid}.gz";
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        BackupCreated::dispatch($this->team->id);
                        $this->backup_standalone_mariadb($database);
                    } elseif ($this->database instanceof StandaloneClickhouse) {
                        $this->backup_file = '/clickhouse-backup-'.Carbon::now()->timestamp."-{$this->backup_log_uuid}.zip";
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        BackupCreated::dispatch($this->team->id);
                        $this->backup_standalone_clickhouse($database);
                    } elseif ($this->database instanceof StandaloneSqlite) {
                        $this->backup_file = "/sqlite-backup-$database-".Carbon::now()->timestamp."-{$this->backup_log_uuid}.gz";
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'uuid' => $this->backup_log_uuid,
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                            'local_storage_deleted' => false,
                        ]);
                        BackupCreated::dispatch($this->team->id);
                        $this->backup_standalone_sqlite($database);
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
                } catch (Throwable $e) {
                    // Local backup failed: the partial file is not tracked, so retention would never remove it.
                    deleteBackupsLocally($this->backup_location, $this->server);
                    if ($this->backup_log) {
                        $this->backup_log->update([
                            'status' => 'failed',
                            'message' => $this->error_output ?? $this->backup_output ?? $e->getMessage(),
                            'size' => $size,
                            'filename' => null,
                            's3_uploaded' => null,
                            'finished_at' => Carbon::now()->toImmutable(),
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
                $localStorageDeleted = false;
                if ($this->backup->save_s3 && $localBackupSucceeded) {
                    try {
                        $s3UploadError = $this->uploadToS3Destinations();

                        // If local backup is disabled, delete the local file only when every destination has a copy
                        if ($s3UploadError === null && $this->backup->disable_local_backup) {
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
                        'finished_at' => Carbon::now()->toImmutable(),
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
            if ($this->backup_log && $this->backup_log->status === 'success') {
                $this->removeExpiredBackups();
            }
        } catch (Throwable $e) {
            $failed = true;
            throw $e;
        } finally {
            if (! $failed && $this->occurrenceUuid) {
                app(ScheduledJobDeliveryService::class)->complete($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid);
            }

            if ($this->backup_log) {
                $this->backup_log->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            }
            if ($this->team) {
                BackupCreated::dispatch($this->team->id);
            }
        }
    }

    private function removeExpiredBackups(): void
    {
        try {
            removeOldBackups($this->backup);
        } catch (Throwable $exception) {
            Log::channel('scheduled-errors')->warning('Database backup retention cleanup failed', [
                'backup_id' => $this->backup->id,
                'execution_id' => $this->backup_log?->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function commandTimeout(): int
    {
        return (int) ($this->backup->timeout ?? 3600);
    }

    private function backupFilenamePart(string $database): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '-', $database);
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
            $escapedContainerName = escapeshellarg($this->container_name);
            $escapedBackupLocation = escapeshellarg($this->backup_location);
            if ($databaseWithCollections === 'all') {
                $commands = $this->backupDirectoryCommands();
                if (str($this->database->image)->startsWith('mongo:4')) {
                    $commands[] = "docker exec {$escapedContainerName} mongodump --uri=$escapedUrl --gzip --archive > {$escapedBackupLocation}";
                } else {
                    $commands[] = "docker exec {$escapedContainerName} mongodump --authenticationDatabase=admin --uri=$escapedUrl --gzip --archive > {$escapedBackupLocation}";
                }
            } else {
                if (str($databaseWithCollections)->contains(':')) {
                    $databaseName = str($databaseWithCollections)->before(':');
                    $collectionsToExclude = str($databaseWithCollections)->after(':')->explode(',');
                } else {
                    $databaseName = $databaseWithCollections;
                    $collectionsToExclude = collect();
                }
                $commands = $this->backupDirectoryCommands();

                // Validate and escape database name to prevent command injection
                validateShellSafePath($databaseName, 'database name');
                $escapedDatabaseName = escapeshellarg($databaseName);

                if ($collectionsToExclude->count() === 0) {
                    if (str($this->database->image)->startsWith('mongo:4')) {
                        $commands[] = "docker exec {$escapedContainerName} mongodump --uri=$escapedUrl --gzip --archive > {$escapedBackupLocation}";
                    } else {
                        $commands[] = "docker exec {$escapedContainerName} mongodump --authenticationDatabase=admin --uri=$escapedUrl --db $escapedDatabaseName --gzip --archive > {$escapedBackupLocation}";
                    }
                } else {
                    // Validate and escape each collection name
                    $escapedCollections = $collectionsToExclude->map(function ($collection) {
                        $collection = trim($collection);
                        validateShellSafePath($collection, 'collection name');

                        return escapeshellarg($collection);
                    });

                    if (str($this->database->image)->startsWith('mongo:4')) {
                        $commands[] = "docker exec {$escapedContainerName} mongodump --uri=$escapedUrl --gzip --excludeCollection ".$escapedCollections->implode(' --excludeCollection ')." --archive > {$escapedBackupLocation}";
                    } else {
                        $commands[] = "docker exec {$escapedContainerName} mongodump --authenticationDatabase=admin --uri=$escapedUrl --db $escapedDatabaseName --gzip --excludeCollection ".$escapedCollections->implode(' --excludeCollection ')." --archive > {$escapedBackupLocation}";
                    }
                }
            }
            $this->backup_output = instant_remote_process($this->writeBackupFileAsRoot($commands), $this->server, true, false, $this->commandTimeout(), disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    /** @return array<int, string> */
    private function databasesToBackup(string $databaseType, string|array $databases): array
    {
        $type = str($databaseType);

        if ($this->backup->dump_all && $type->contains(['postgres', 'mysql', 'mariadb'])) {
            return ['all'];
        }

        if (is_array($databases)) {
            return $databases;
        }

        if ($type->contains('mongo')) {
            return array_map('trim', explode('|', $databases));
        }

        if ($type->contains(['postgres', 'mysql', 'mariadb', 'clickhouse', 'sqlite'])) {
            return array_map('trim', explode(',', $databases));
        }

        return [];
    }

    private function backup_standalone_postgresql(string $database): void
    {
        try {
            $commands = $this->backupDirectoryCommands();
            $backupCommand = 'docker exec';
            if ($this->postgres_password) {
                $backupCommand .= ' -e PGPASSWORD='.escapeshellarg($this->postgres_password);
            }
            $escapedUsername = escapeshellarg($this->database->postgres_user);
            $escapedContainerName = escapeshellarg($this->container_name);
            $escapedBackupLocation = escapeshellarg($this->backup_location);
            if ($this->backup->dump_all) {
                $backupCommand .= " {$escapedContainerName} pg_dumpall --username $escapedUsername";
                $backupCommand = $this->buildCompressedDumpCommand($backupCommand, $escapedBackupLocation);
            } else {
                // Validate and escape database name to prevent command injection
                validateShellSafePath($database, 'database name');
                $escapedDatabase = escapeshellarg($database);
                $backupCommand .= " {$escapedContainerName} pg_dump --format=custom --no-acl --no-owner --username $escapedUsername $escapedDatabase > {$escapedBackupLocation}";
            }

            $commands[] = $backupCommand;
            $this->backup_output = instant_remote_process($this->writeBackupFileAsRoot($commands), $this->server, true, false, $this->commandTimeout(), disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mysql(string $database): void
    {
        try {
            $commands = $this->backupDirectoryCommands();
            $escapedPassword = escapeshellarg($this->database->mysql_root_password);
            $escapedContainerName = escapeshellarg($this->container_name);
            $escapedBackupLocation = escapeshellarg($this->backup_location);
            if ($this->backup->dump_all) {
                $dumpCommand = "docker exec {$escapedContainerName} mysqldump -u root -p$escapedPassword --all-databases --single-transaction --quick --lock-tables=false";
                $commands[] = $this->buildCompressedDumpCommand($dumpCommand, $escapedBackupLocation);
            } else {
                // Validate and escape database name to prevent command injection
                validateShellSafePath($database, 'database name');
                $escapedDatabase = escapeshellarg($database);
                $commands[] = "docker exec {$escapedContainerName} mysqldump -u root -p$escapedPassword $escapedDatabase > {$escapedBackupLocation}";
            }
            $this->backup_output = instant_remote_process($this->writeBackupFileAsRoot($commands), $this->server, true, false, $this->commandTimeout(), disableMultiplexing: true);
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
            $commands = $this->backupDirectoryCommands();
            $escapedPassword = escapeshellarg($this->database->mariadb_root_password);
            $escapedContainerName = escapeshellarg($this->container_name);
            $escapedBackupLocation = escapeshellarg($this->backup_location);
            if ($this->backup->dump_all) {
                $dumpCommand = "docker exec {$escapedContainerName} mariadb-dump -u root -p$escapedPassword --all-databases --single-transaction --quick --lock-tables=false";
                $commands[] = $this->buildCompressedDumpCommand($dumpCommand, $escapedBackupLocation);
            } else {
                // Validate and escape database name to prevent command injection
                validateShellSafePath($database, 'database name');
                $escapedDatabase = escapeshellarg($database);
                $commands[] = "docker exec {$escapedContainerName} mariadb-dump -u root -p$escapedPassword $escapedDatabase > {$escapedBackupLocation}";
            }
            $this->backup_output = instant_remote_process($this->writeBackupFileAsRoot($commands), $this->server, true, false, $this->commandTimeout(), disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_clickhouse(string $database): void
    {
        $archiveName = ltrim($this->backup_file, '/');

        try {
            $commands = [...$this->backupDirectoryCommands(), ...ClickhouseBackupCommand::make(
                containerName: $this->container_name,
                database: $database,
                archiveName: $archiveName,
                backupDirectory: $this->backup_dir,
            )];

            $this->backup_output = instant_remote_process($this->writeBackupFileAsRoot($commands), $this->server, true, false, $this->commandTimeout(), disableMultiplexing: true);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        } finally {
            $cleanupCommand = ClickhouseBackupCommand::cleanup($this->container_name, $archiveName);
            instant_remote_process([$cleanupCommand], $this->server, false, false, null, disableMultiplexing: true);
        }
    }

    private function backup_standalone_sqlite(string $database): void
    {
        try {
            if (! preg_match(StandaloneSqlite::DATABASES_PATTERN, $database)) {
                throw new \Exception("Invalid database file name: {$database}");
            }
            $commands = $this->backupDirectoryCommands();
            $script = 'f=$(mktemp) && sqlite3 -readonly '.escapeshellarg(StandaloneSqlite::DATA_DIRECTORY.'/'.$database).' \'.timeout 10000\' "VACUUM INTO \'$f\'" && cat "$f"; s=$?; rm -f "$f"; exit $s';
            $dumpCommand = 'docker exec '.escapeshellarg($this->container_name).' sh -c '.escapeshellarg($script);
            $commands[] = $this->buildCompressedDumpCommand($dumpCommand, escapeshellarg($this->backup_location));
            $this->backup_output = instant_remote_process($this->writeBackupFileAsRoot($commands), $this->server, true, false, $this->commandTimeout(), disableMultiplexing: true);
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
        return instant_remote_process(['du -b '.escapeshellarg($this->backup_location).' | cut -f1'], $this->server, false, false, null, disableMultiplexing: true);
    }

    /**
     * Host path of the backup file for the upload container. It differs from backup_location only for the
     * development testing-host server (see devHostDockerPath()).
     */
    private function backupMountSource(): string
    {
        return devHostDockerPath($this->server, $this->backup_location);
    }

    /**
     * Uploads the backup file to every selected S3 destination and records one replica per destination.
     *
     * @return string|null The failed destinations and their errors, or null when every upload succeeded.
     */
    private function uploadToS3Destinations(): ?string
    {
        $storages = $this->backup->selectedS3Storages();
        if ($storages->isEmpty()) {
            $previousS3StorageId = $this->backup->s3_storage_id;

            $this->backup->update([
                'save_s3' => false,
                's3_storage_id' => null,
            ]);

            throw new \Exception('S3 storage configuration is missing or has been deleted (S3 storage ID: '.($previousS3StorageId ?? 'null').'). S3 backup has been disabled for this schedule.');
        }

        $failures = [];
        foreach ($storages as $storage) {
            try {
                $this->upload_to_s3($storage);
                $this->backup_log->s3Replicas()->create([
                    's3_storage_id' => $storage->id,
                    's3_uploaded' => true,
                ]);
            } catch (Throwable $e) {
                $this->backup_log->s3Replicas()->create([
                    's3_storage_id' => $storage->id,
                    's3_uploaded' => false,
                    'message' => $e->getMessage(),
                ]);
                $failures[] = "{$storage->name}: {$e->getMessage()}";
            }
        }

        $this->backup_log->refreshS3Summary();
        $this->s3_uploaded = $failures === [];

        return $failures === [] ? null : implode("\n", $failures);
    }

    protected function upload_to_s3(S3Storage $s3): void
    {
        try {
            $key = $s3->key;
            $secret = $s3->secret;
            $bucket = $s3->bucket;
            $endpoint = $s3->endpoint;
            $s3->testConnection(shouldSave: true);
            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $network = $this->database->service->destination->network;
            } else {
                $network = $this->database->destination->network;
            }
            $safeNetwork = escapeshellarg($network);

            $fullImageName = $this->getFullImageName();

            // Remove a leftover helper in the same batch, so a replayed batch does not fail with a name conflict.
            $commands[] = "docker rm -f backup-of-{$this->backup_log_uuid} >/dev/null 2>&1 || true";
            $mount = escapeshellarg($this->backupMountSource().':'.$this->backup_location.':ro');
            $commands[] = "docker run -d --network {$safeNetwork} --name backup-of-{$this->backup_log_uuid} --rm -v {$mount} {$fullImageName}";

            // Escape S3 credentials to prevent command injection
            $escapedEndpoint = escapeshellarg($endpoint);
            $escapedKey = escapeshellarg($key);
            $escapedSecret = escapeshellarg($secret);
            $escapedBackupLocation = escapeshellarg($this->backup_location);
            $escapedS3Destination = escapeshellarg("temporary/{$bucket}{$this->backup_dir}/");
            $resolveOptions = collect(SafeWebhookUrl::minioClientResolveOptions($endpoint, $s3->trustedInternalHosts()))
                ->map(fn (string $resolveOption): string => '--resolve '.escapeshellarg($resolveOption))
                ->implode(' ');
            $resolveOptions = $resolveOptions === '' ? '' : ' '.$resolveOptions;

            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc alias set{$resolveOptions} temporary {$escapedEndpoint} {$escapedKey} {$escapedSecret}";
            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc cp {$escapedBackupLocation} {$escapedS3Destination}";
            instant_remote_process($commands, $this->server, true, false, $this->commandTimeout(), disableMultiplexing: true);

        } catch (Throwable $e) {
            $this->add_to_error_output($e->getMessage());
            throw $e;
        } finally {
            $command = dockerRemoveCommand("backup-of-{$this->backup_log_uuid}");
            instant_remote_process([$command], $this->server, true, false, null, disableMultiplexing: true);
        }
    }

    private function getFullImageName(): string
    {
        $helperImage = coolifyHelperImage();
        $latestVersion = getHelperVersion();

        return "{$helperImage}:{$latestVersion}";
    }

    /**
     * Creates the backup directory. On a server with a non-root SSH user, the directory gets the ownership step
     * that the sudo parser adds only to unquoted paths: the SSH user owns it (backup downloads read it over SFTP
     * as that user) and other users cannot read the dumps in it.
     *
     * @return array<int, string>
     */
    private function backupDirectoryCommands(): array
    {
        $backupDirectory = escapeshellarg($this->backup_dir);
        $commands = ['mkdir -p '.$backupDirectory];
        if ($this->server->isNonRoot()) {
            $commands[] = ownershipCommand($backupDirectory, $this->server);
        }

        return $commands;
    }

    /**
     * The SSH user's shell opens a `>` redirect. For a non-root SSH user, the dump and the redirect into
     * the backup directory must run in one root shell, so each line that writes the backup file becomes
     * one `sh -c` script. The exit status of the dump stays the exit status of the line.
     *
     * @param  list<string>  $commands
     * @return list<string>
     */
    private function writeBackupFileAsRoot(array $commands): array
    {
        $redirect = '> '.escapeshellarg($this->backup_location);

        return array_map(
            fn (string $command): string => str_contains($command, $redirect) ? 'sh -c '.escapeshellarg($command) : $command,
            $commands,
        );
    }

    private function buildCompressedDumpCommand(string $dumpCommand, string $escapedBackupLocation): string
    {
        $cpuPercentage = BackupCompression::cpuPercentage($this->server->settings->backup_compression_cpu_percentage);
        $compressorCommand = BackupCompression::compressorCommand($cpuPercentage);
        $script = "compressor=\$({$compressorCommand}); exec \$compressor";

        return pipeToFileKeepingExitStatus($dumpCommand, 'docker run --rm -i '.escapeshellarg($this->getFullImageName()).' sh -c '.escapeshellarg($script), $escapedBackupLocation);
    }

    /**
     * Log a run that ends without a backup execution, so it does not look like a missed schedule.
     *
     * @param  array<string, mixed>  $context
     */
    private function logSkippedRun(string $reason, array $context = []): void
    {
        Log::channel('scheduled')->warning('Database backup job ended without a backup', [
            'skip_reason' => $reason,
            'backup_id' => $this->backup->id,
            'database_id' => $this->backup->database_id,
            'database_type' => $this->backup->database_type,
            'team_id' => $this->backup->team_id,
            'occurrence_uuid' => $this->occurrenceUuid,
            ...$context,
        ]);
    }

    /**
     * A start, restart or import of the database is reserved, queued or running. Stale activities do
     * not count; they are failed by the start and import paths.
     */
    private function databaseOperationInProgress(): bool
    {
        $uuid = $this->database->uuid;
        if (blank($uuid)) {
            return false;
        }

        if (DatabaseOperationReservation::isHeldByOther($uuid)) {
            return true;
        }

        $liveOperation = collect([ResourceStartActivity::DATABASE_START_OPERATION, ResourceStartActivity::DATABASE_IMPORT_OPERATION])
            ->flatMap(fn (string $operation) => ResourceStartActivity::active($uuid, $operation))
            ->contains(fn (Activity $activity): bool => ! ResourceStartActivity::isStale($activity));

        return $liveOperation
            || ResourceStartActivity::latestRunning($uuid) !== null
            || ($this->database instanceof ServiceDatabase && ResourceStartActivity::latestRunning($this->database->service->uuid) !== null);
    }

    /**
     * Show a backup run that did not take a backup in the execution list. The run is expected, so it
     * sends no failure notification.
     */
    private function recordSkippedBackup(string $message): void
    {
        $databaseNames = $this->backup->dump_all ? 'all' : data_get($this->backup, 'databases_to_backup');
        $this->backup_log_uuid = new_public_id();
        $this->backup_log = ScheduledDatabaseBackupExecution::create([
            'uuid' => $this->backup_log_uuid,
            'database_name' => filled($databaseNames) ? Str::limit($databaseNames, 250) : null,
            'scheduled_database_backup_id' => $this->backup->id,
            'status' => 'failed',
            'message' => $message,
            'filename' => null,
            'local_storage_deleted' => false,
        ]);
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
        if ($this->occurrenceUuid) {
            app(ScheduledJobDeliveryService::class)->fail($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid);
        }

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

        // After a worker timeout this runs on a fresh job copy without the state handle() set.
        $this->team ??= Team::find($this->backup->team_id);
        $executions = $this->executionsOfThisRun();

        // Don't overwrite a finished execution: handle() already reported it, and a post-backup
        // error (e.g. notification failure) must not mark a successful backup as failed (#9088).
        $unfinished = $executions->whereNotIn('status', ['success', 'failed']);
        foreach ($unfinished as $execution) {
            $execution->update([
                'status' => 'failed',
                'message' => 'Job permanently failed after '.$this->attempts().' attempts: '.($exception?->getMessage() ?? 'Unknown error'),
                'size' => 0,
                'filename' => null,
                'finished_at' => Carbon::now(),
            ]);
        }

        if ($executions->isNotEmpty() && $unfinished->isEmpty()) {
            return;
        }

        $output = $this->backup_output ?? $exception?->getMessage() ?? 'Unknown error';
        $databaseNames = $unfinished->isEmpty() ? ['unknown'] : $unfinished->map(fn (ScheduledDatabaseBackupExecution $execution) => $execution->database_name ?? 'unknown');
        foreach ($databaseNames as $databaseName) {
            try {
                $this->team?->notify(new BackupFailed($this->backup, $this->backup->database, $output, $databaseName));
            } catch (Throwable $e) {
                Log::channel('scheduled-errors')->warning('Failed to send backup permanent failure notification', [
                    'backup_id' => $this->backup->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return Collection<int, ScheduledDatabaseBackupExecution>
     */
    private function executionsOfThisRun(): Collection
    {
        if ($this->executionUuidPrefix === null && $this->backup_log_uuid === null) {
            return collect();
        }

        return ScheduledDatabaseBackupExecution::query()
            ->where('scheduled_database_backup_id', $this->backup->id)
            ->where(fn ($query) => $query
                ->when($this->executionUuidPrefix !== null, fn ($query) => $query->orWhere('uuid', 'like', $this->executionUuidPrefix.'%'))
                ->when($this->backup_log_uuid !== null, fn ($query) => $query->orWhere('uuid', $this->backup_log_uuid)))
            ->get();
    }
}
