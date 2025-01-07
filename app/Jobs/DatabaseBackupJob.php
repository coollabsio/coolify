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
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class DatabaseBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

    public ?string $postgres_password = null;

    public ?S3Storage $s3 = null;

    public function __construct(public ScheduledDatabaseBackup $scheduledDatabaseBackup)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            $databasesToBackup = null;

            $this->team = Team::query()->find($this->scheduledDatabaseBackup->team_id);
            if (! $this->team) {
                $this->scheduledDatabaseBackup->delete();

                return;
            }
            if (data_get($this->scheduledDatabaseBackup, 'database_type') === ServiceDatabase::class) {
                $this->database = data_get($this->scheduledDatabaseBackup, 'database');
                $this->server = $this->database->service->server;
                $this->s3 = $this->scheduledDatabaseBackup->s3;
            } else {
                $this->database = data_get($this->scheduledDatabaseBackup, 'database');
                $this->server = $this->database->destination->server;
                $this->s3 = $this->scheduledDatabaseBackup->s3;
            }
            if (is_null($this->server)) {
                throw new Exception('Server not found?!');
            }
            if (is_null($this->database)) {
                throw new Exception('Database not found?!');
            }

            BackupCreated::dispatch($this->team->id);

            $status = str(data_get($this->database, 'status'));
            if (! $status->startsWith('running') && $this->database->id !== 0) {
                return;
            }
            if (data_get($this->scheduledDatabaseBackup, 'database_type') === ServiceDatabase::class) {
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
                    $this->database->postgres_user = $user ? str($user)->after('POSTGRES_USER=')->value() : 'postgres';

                    $db = $envs->filter(function ($env) {
                        return str($env)->startsWith('POSTGRES_DB=');
                    })->first();

                    $databasesToBackup = $db ? str($db)->after('POSTGRES_DB=')->value() : $this->database->postgres_user;
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
                        throw new Exception('MYSQL_DATABASE not found');
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
                            throw new Exception('MARIADB_DATABASE or MYSQL_DATABASE not found');
                        }
                    }
                }
            } else {
                $databaseName = str($this->database->name)->slug()->value();
                $this->container_name = $this->database->uuid;
                $this->directory_name = $databaseName.'-'.$this->container_name;
                $databaseType = $this->database->type();
                $databasesToBackup = data_get($this->scheduledDatabaseBackup, 'databases_to_backup');
            }
            if (blank($databasesToBackup)) {
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
            } elseif (str($databaseType)->contains('postgres')) {
                // Format: db1,db2,db3
                $databasesToBackup = explode(',', $databasesToBackup);
                $databasesToBackup = array_map('trim', $databasesToBackup);
            } elseif (str($databaseType)->contains('mongodb')) {
                // Format: db1:collection1,collection2|db2:collection3,collection4
                $databasesToBackup = explode('|', $databasesToBackup);
                $databasesToBackup = array_map('trim', $databasesToBackup);
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
            $this->backup_dir = backup_dir().'/databases/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->directory_name;
            if ($this->database->name === 'coolify-db') {
                $databasesToBackup = ['coolify'];
                $this->directory_name = $this->container_name = 'coolify-db';
                $ip = Str::slug($this->server->ip);
                $this->backup_dir = backup_dir().'/coolify'."/coolify-db-$ip";
            }
            foreach ($databasesToBackup as $databaseToBackup) {
                $size = 0;
                try {
                    if (str($databaseType)->contains('postgres')) {
                        $this->backup_file = "/pg-dump-{$databaseToBackup}-".Carbon::now()->timestamp.'.dmp';
                        if ($this->scheduledDatabaseBackup->dump_all) {
                            $this->backup_file = '/pg-dump-all-'.Carbon::now()->timestamp.'.gz';
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::query()->create([
                            'database_name' => $databaseToBackup,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->scheduledDatabaseBackup->id,
                        ]);
                        $this->backup_standalone_postgresql($databaseToBackup);
                    } elseif (str($databaseType)->contains('mongodb')) {
                        if ($databaseToBackup === '*') {
                            $databaseToBackup = 'all';
                            $databaseName = 'all';
                        } elseif (str($databaseToBackup)->contains(':')) {
                            $databaseName = str($databaseToBackup)->before(':');
                        } else {
                            $databaseName = $databaseToBackup;
                        }
                        $this->backup_file = "/mongo-dump-$databaseName-".Carbon::now()->timestamp.'.tar.gz';
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::query()->create([
                            'database_name' => $databaseName,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->scheduledDatabaseBackup->id,
                        ]);
                        $this->backup_standalone_mongodb($databaseToBackup);
                    } elseif (str($databaseType)->contains('mysql')) {
                        $this->backup_file = "/mysql-dump-{$databaseToBackup}-".Carbon::now()->timestamp.'.dmp';
                        if ($this->scheduledDatabaseBackup->dump_all) {
                            $this->backup_file = '/mysql-dump-all-'.Carbon::now()->timestamp.'.gz';
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::query()->create([
                            'database_name' => $databaseToBackup,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->scheduledDatabaseBackup->id,
                        ]);
                        $this->backup_standalone_mysql($databaseToBackup);
                    } elseif (str($databaseType)->contains('mariadb')) {
                        $this->backup_file = "/mariadb-dump-{$databaseToBackup}-".Carbon::now()->timestamp.'.dmp';
                        if ($this->scheduledDatabaseBackup->dump_all) {
                            $this->backup_file = '/mariadb-dump-all-'.Carbon::now()->timestamp.'.gz';
                        }
                        $this->backup_location = $this->backup_dir.$this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::query()->create([
                            'database_name' => $databaseToBackup,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->scheduledDatabaseBackup->id,
                        ]);
                        $this->backup_standalone_mariadb($databaseToBackup);
                    } else {
                        throw new Exception('Unsupported database type');
                    }
                    $size = $this->calculate_size();
                    $this->remove_old_backups();
                    if ($this->scheduledDatabaseBackup->save_s3) {
                        $this->upload_to_s3();
                    }

                    $this->team->notify(new BackupSuccess($this->scheduledDatabaseBackup, $this->database, $databaseToBackup));

                    $this->backup_log->update([
                        'status' => 'success',
                        'message' => $this->backup_output,
                        'size' => $size,
                    ]);
                } catch (Throwable $e) {
                    if ($this->backup_log instanceof ScheduledDatabaseBackupExecution) {
                        $this->backup_log->update([
                            'status' => 'failed',
                            'message' => $this->backup_output,
                            'size' => $size,
                            'filename' => null,
                        ]);
                    }
                    $this->team?->notify(new BackupFailed($this->scheduledDatabaseBackup, $this->database, $this->backup_output, $databaseToBackup));
                }
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            if ($this->team instanceof Team) {
                BackupCreated::dispatch($this->team->id);
            }
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
                } elseif (str($this->database->image)->startsWith('mongo:4')) {
                    $commands[] = "docker exec $this->container_name mongodump --uri=$url --gzip --excludeCollection ".$collectionsToExclude->implode(' --excludeCollection ')." --archive > $this->backup_location";
                } else {
                    $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$url --db $databaseName --gzip --excludeCollection ".$collectionsToExclude->implode(' --excludeCollection ')." --archive > $this->backup_location";
                }
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
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
            if ($this->scheduledDatabaseBackup->dump_all) {
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
        } catch (Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mysql(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            if ($this->scheduledDatabaseBackup->dump_all) {
                $commands[] = "docker exec $this->container_name mysqldump -u root -p{$this->database->mysql_root_password} --all-databases --single-transaction --quick --lock-tables=false --compress | gzip > $this->backup_location";
            } else {
                $commands[] = "docker exec $this->container_name mysqldump -u root -p{$this->database->mysql_root_password} $database > $this->backup_location";
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_mariadb(string $database): void
    {
        try {
            $commands[] = 'mkdir -p '.$this->backup_dir;
            if ($this->scheduledDatabaseBackup->dump_all) {
                $commands[] = "docker exec $this->container_name mariadb-dump -u root -p{$this->database->mariadb_root_password} --all-databases --single-transaction --quick --lock-tables=false --compress > $this->backup_location";
            } else {
                $commands[] = "docker exec $this->container_name mariadb-dump -u root -p{$this->database->mariadb_root_password} $database > $this->backup_location";
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
        } catch (Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            throw $e;
        }
    }

    private function add_to_backup_output($output): void
    {
        $this->backup_output = $this->backup_output ? $this->backup_output."\n".$output : $output;
    }

    private function calculate_size()
    {
        return instant_remote_process(["du -b $this->backup_location | cut -f1"], $this->server, false);
    }

    private function remove_old_backups(): void
    {
        if ($this->scheduledDatabaseBackup->number_of_backups_locally === 0) {
            $deletable = $this->scheduledDatabaseBackup->executions()->where('status', 'success');
        } else {
            $deletable = $this->scheduledDatabaseBackup->executions()->where('status', 'success')->skip($this->scheduledDatabaseBackup->number_of_backups_locally - 1);
        }
        foreach ($deletable->get() as $execution) {
            delete_backup_locally($execution->filename, $this->server);
            $execution->delete();
        }
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
            if (data_get($this->scheduledDatabaseBackup, 'database_type') === ServiceDatabase::class) {
                $network = $this->database->service->destination->network;
            } else {
                $network = $this->database->destination->network;
            }

            $fullImageName = $this->getFullImageName();

            if (isDev()) {
                if ($this->database->name === 'coolify-db') {
                    $backup_location_from = '/var/lib/docker/volumes/coolify_dev_backups_data/_data/coolify/coolify-db-'.$this->server->ip.$this->backup_file;
                    $commands[] = "docker run -d --network {$network} --name backup-of-{$this->scheduledDatabaseBackup->uuid} --rm -v $backup_location_from:$this->backup_location:ro {$fullImageName}";
                } else {
                    $backup_location_from = '/var/lib/docker/volumes/coolify_dev_backups_data/_data/databases/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->directory_name.$this->backup_file;
                    $commands[] = "docker run -d --network {$network} --name backup-of-{$this->scheduledDatabaseBackup->uuid} --rm -v $backup_location_from:$this->backup_location:ro {$fullImageName}";
                }
            } else {
                $commands[] = "docker run -d --network {$network} --name backup-of-{$this->scheduledDatabaseBackup->uuid} --rm -v $this->backup_location:$this->backup_location:ro {$fullImageName}";
            }
            if ($this->s3->isHetzner()) {
                $endpointWithoutBucket = 'https://'.str($endpoint)->after('https://')->after('.')->value();
                $commands[] = "docker exec backup-of-{$this->scheduledDatabaseBackup->uuid} mc alias set --path=off --api=S3v4 temporary {$endpointWithoutBucket} $key $secret";
            } else {
                $commands[] = "docker exec backup-of-{$this->scheduledDatabaseBackup->uuid} mc config host add temporary {$endpoint} $key $secret";
            }
            $commands[] = "docker exec backup-of-{$this->scheduledDatabaseBackup->uuid} mc cp $this->backup_location temporary/$bucket{$this->backup_dir}/";
            instant_remote_process($commands, $this->server);

            $this->add_to_backup_output('Uploaded to S3.');
        } catch (Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            throw $e;
        } finally {
            $command = "docker rm -f backup-of-{$this->scheduledDatabaseBackup->uuid}";
            instant_remote_process([$command], $this->server);
        }
    }

    private function getFullImageName(): string
    {
        $settings = instanceSettings();
        $helperImage = config('constants.coolify.helper_image');
        $latestVersion = $settings->helper_version;

        return "{$helperImage}:{$latestVersion}";
    }
}
