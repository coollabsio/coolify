<?php

namespace App\Jobs;

use App\Actions\Database\StopDatabase;
use App\Events\BackupCreated;
use App\Models\InstanceSettings;
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
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

class DatabaseBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?Team $team = null;

    public Server $server;

    public ScheduledDatabaseBackup $backup;

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

    public ?string $postgres_password = null;

    public ?S3Storage $s3 = null;

    public function __construct($backup)
    {
        $this->backup = $backup;
        $this->team = Team::find($backup->team_id);
        if (is_null($this->team)) {
            return;
        }
        if (data_get($this->backup, 'database_type') === 'App\Models\ServiceDatabase') {
            $this->database = data_get($this->backup, 'database');
            $this->server = $this->database->service->server;
            $this->s3 = $this->backup->s3;
        } else {
            $this->database = data_get($this->backup, 'database');
            $this->server = $this->database->destination->server;
            $this->s3 = $this->backup->s3;
        }
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->backup->id)];
    }

    public function uniqueId(): int
    {
        return $this->backup->id;
    }

    public function handle(): void
    {
        try {
            // Check if team is exists
            if (is_null($this->team)) {
                $this->backup->update(['status' => 'failed']);
                StopDatabase::run($this->database);
                $this->database->delete();

                return;
            }

            BackupCreated::dispatch($this->team->id);

            $status = str(data_get($this->database, 'status'));
            if (! $status->startsWith('running') && $this->database->id !== 0) {
                ray('database not running');

                return;
            }
            if (data_get($this->backup, 'database_type') === 'App\Models\ServiceDatabase') {
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
                }
            } else {
                $databaseName = str($this->database->name)->slug()->value();
                $this->container_name = $this->database->uuid;
                $this->directory_name = $databaseName.'-'.$this->container_name;
                $databaseType = $this->database->type();
                $databasesToBackup = data_get($this->backup, 'databases_to_backup');
            }

            if (is_null($databasesToBackup)) {
                if (str($databaseType)->contains('postgres')) {
                    $databasesToBackup = [$this->database->postgres_db];
                } elseif (str($databaseType)->contains('mongodb')) {
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
                } elseif (str($databaseType)->contains('mongodb')) {
                    // Format: db1:collection1,collection2|db2:collection3,collection4
                    $databasesToBackup = explode('|', $databasesToBackup);
                    $databasesToBackup = array_map('trim', $databasesToBackup);
                    ray($databasesToBackup);
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
                $size = 0;
                ray('Backing up '.$database);
                try {
                    if (str($databaseType)->contains('postgres')) {
                        $this->backup_file = "/pg-dump-$database-".Carbon::now()->timestamp.'.dmp';
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                        ]);
                        $this->backup_standalone_postgresql($database);
                    } elseif (str($databaseType)->contains('mongodb')) {
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
                            'database_name' => $databaseName,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                        ]);
                        $this->backup_standalone_mongodb($database);
                    } elseif (str($databaseType)->contains('mysql')) {
                        $this->backup_file = "/mysql-dump-$database-".Carbon::now()->timestamp.'.dmp';
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                        ]);
                        $this->backup_standalone_mysql($database);
                    } elseif (str($databaseType)->contains('mariadb')) {
                        $this->backup_file = "/mariadb-dump-$database-".Carbon::now()->timestamp.'.dmp';
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                        ]);
                        $this->backup_standalone_mariadb($database);
                    } else {
                        throw new \Exception('Unsupported database type');
                    }
                    $size = $this->calculate_size();
                    $this->remove_old_backups();
                    if ($this->backup->save_s3) {
                        $this->upload_to_s3();
                    }
                    $this->team?->notify(new BackupSuccess($this->backup, $this->database, $database));
                    $this->backup_log->update([
                        'status' => 'success',
                        'message' => $this->backup_output,
                        'size' => $size,
                    ]);
                } catch (\Throwable $e) {
                    if ($this->backup_log) {
                        $this->backup_log->update([
                            'status' => 'failed',
                            'message' => $this->backup_output,
                            'size' => $size,
                            'filename' => null,
                        ]);
                    }
                    send_internal_notification('DatabaseBackupJob failed with: '.$e->getMessage());
                    $this->team?->notify(new BackupFailed($this->backup, $this->database, $this->backup_output, $database));
                }
            }
        } catch (\Throwable $e) {
            send_internal_notification('DatabaseBackupJob failed with: '.$e->getMessage());
            throw $e;
        } finally {
            BackupCreated::dispatch($this->team->id);
        }
    }

    private function backup_standalone_mongodb(string $databaseWithCollections): void
    {
        try {
            $url = $this->database->internal_db_url;
            if ($databaseWithCollections === 'all') {
                $commands[] = 'mkdir -p '.$this->backup_dir;
                if (str($this->database->image)->startsWith('mongo:4')) {
                    $commands[] = "docker exec $this->container_name mongodump --uri=$url --gzip --archive > $this->backup_location";
                } else {
                    $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$url --gzip --archive > $this->backup_location";
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
                        $commands[] = "docker exec $this->container_name mongodump --uri=$url --gzip --archive > $this->backup_location";
                    } else {
                        $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$url --db $databaseName --gzip --archive > $this->backup_location";
                    }
                } else {
                    if (str($this->database->image)->startsWith('mongo:4')) {
                        $commands[] = "docker exec $this->container_name mongodump --uri=$url --gzip --excludeCollection ".$collectionsToExclude->implode(' --excludeCollection ')." --archive > $this->backup_location";
                    } else {
                        $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$url --db $databaseName --gzip --excludeCollection ".$collectionsToExclude->implode(' --excludeCollection ')." --archive > $this->backup_location";
                    }
                }
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
            ray('Backup done for '.$this->container_name.' at '.$this->server->name.':'.$this->backup_location);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for '.$this->container_name.' at '.$this->server->name.':'.$this->backup_location.'\n\nError:'.$e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_postgresql(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $backupCommand = 'docker exec';
            if ($this->postgres_password) {
                $backupCommand .= " -e PGPASSWORD=$this->postgres_password";
            }
            $backupCommand .= " $this->container_name pg_dump --format=custom --no-acl --no-owner --username {$this->database->postgres_user} $database > $this->backup_location";

            $commands[] = $backupCommand;
            ray($commands);
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
            ray('Backup done for '.$this->container_name.' at '.$this->server->name.':'.$this->backup_location);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for '.$this->container_name.' at '.$this->server->name.':'.$this->backup_location.'\n\nError:'.$e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mysql(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $commands[] = "docker exec $this->container_name mysqldump -u root -p{$this->database->mysql_root_password} $database > $this->backup_location";
            ray($commands);
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
            ray('Backup done for '.$this->container_name.' at '.$this->server->name.':'.$this->backup_location);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for '.$this->container_name.' at '.$this->server->name.':'.$this->backup_location.'\n\nError:'.$e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mariadb(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            $commands[] = "docker exec $this->container_name mariadb-dump -u root -p{$this->database->mariadb_root_password} $database > $this->backup_location";
            ray($commands);
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
            ray('Backup done for '.$this->container_name.' at '.$this->server->name.':'.$this->backup_location);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for '.$this->container_name.' at '.$this->server->name.':'.$this->backup_location.'\n\nError:'.$e->getMessage());
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

    private function calculate_size()
    {
        return instant_remote_process(["du -b $this->backup_location | cut -f1"], $this->server, false);
    }

    private function remove_old_backups(): void
    {
        if ($this->backup->number_of_backups_locally === 0) {
            $deletable = $this->backup->executions()->where('status', 'success');
        } else {
            $deletable = $this->backup->executions()->where('status', 'success')->skip($this->backup->number_of_backups_locally - 1);
        }
        foreach ($deletable->get() as $execution) {
            delete_backup_locally($execution->filename, $this->server);
            $execution->delete();
        }
    }

    // private function upload_to_s3(): void
    // {
    //     try {
    //         if (is_null($this->s3)) {
    //             return;
    //         }
    //         $key = $this->s3->key;
    //         $secret = $this->s3->secret;
    //         // $region = $this->s3->region;
    //         $bucket = $this->s3->bucket;
    //         $endpoint = $this->s3->endpoint;
    //         $this->s3->testConnection(shouldSave: true);
    //         $configName = new Cuid2;

    //         $s3_copy_dir = str($this->backup_location)->replace(backup_dir(), '/var/www/html/storage/app/backups/');
    //         $commands[] = "docker exec coolify bash -c 'mc config host add {$configName} {$endpoint} $key $secret'";
    //         $commands[] = "docker exec coolify bash -c 'mc cp $s3_copy_dir {$configName}/{$bucket}{$this->backup_dir}/'";
    //         instant_remote_process($commands, $this->server);
    //         $this->add_to_backup_output('Uploaded to S3.');
    //     } catch (\Throwable $e) {
    //         $this->add_to_backup_output($e->getMessage());
    //         throw $e;
    //     } finally {
    //         $removeConfigCommands[] = "docker exec coolify bash -c 'mc config remove {$configName}'";
    //         $removeConfigCommands[] = "docker exec coolify bash -c 'mc alias rm {$configName}'";
    //         instant_remote_process($removeConfigCommands, $this->server, false);
    //     }
    // }
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
            if (data_get($this->backup, 'database_type') === 'App\Models\ServiceDatabase') {
                $network = $this->database->service->destination->network;
            } else {
                $network = $this->database->destination->network;
            }

            $this->ensureHelperImageAvailable();

            $fullImageName = $this->getFullImageName();
            $commands[] = "docker run -d --network {$network} --name backup-of-{$this->backup->uuid} --rm -v $this->backup_location:$this->backup_location:ro {$fullImageName}";
            $commands[] = "docker exec backup-of-{$this->backup->uuid} mc config host add temporary {$endpoint} $key $secret";
            $commands[] = "docker exec backup-of-{$this->backup->uuid} mc cp $this->backup_location temporary/$bucket{$this->backup_dir}/";
            instant_remote_process($commands, $this->server);
            $this->add_to_backup_output('Uploaded to S3.');
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            throw $e;
        } finally {
            $command = "docker rm -f backup-of-{$this->backup->uuid}";
            instant_remote_process([$command], $this->server);
        }
    }

    private function ensureHelperImageAvailable(): void
    {
        $fullImageName = $this->getFullImageName();

        $imageExists = $this->checkImageExists($fullImageName);

        if (! $imageExists) {
            $this->pullHelperImage($fullImageName);
        }
    }

    private function checkImageExists(string $fullImageName): bool
    {
        $result = instant_remote_process(["docker image inspect {$fullImageName} >/dev/null 2>&1 && echo 'exists' || echo 'not exists'"], $this->server, false);

        return trim($result) === 'exists';
    }

    private function pullHelperImage(string $fullImageName): void
    {
        try {
            instant_remote_process(["docker pull {$fullImageName}"], $this->server);
        } catch (\Exception $e) {
            $errorMessage = 'Failed to pull helper image: '.$e->getMessage();
            $this->add_to_backup_output($errorMessage);
            throw new \RuntimeException($errorMessage);
        }
    }

    private function getFullImageName(): string
    {
        $settings = InstanceSettings::get();
        $helperImage = config('coolify.helper_image');
        $latestVersion = $settings->helper_version;

        return "{$helperImage}:{$latestVersion}";
    }
}
