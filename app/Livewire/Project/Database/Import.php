<?php

namespace App\Livewire\Project\Database;

use App\Models\Server;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class Import extends Component
{
    public bool $unsupported = false;

    public $resource;

    public $parameters;

    public $containers;

    public bool $scpInProgress = false;

    public bool $importRunning = false;

    public ?string $filename = null;

    public ?string $filesize = null;

    public bool $isUploading = false;

    public int $progress = 0;

    public bool $error = false;

    public Server $server;

    public string $container;

    public array $importCommands = [];

    public bool $dumpAll = false;

    public string $restoreCommandText = '';

    public string $customLocation = '';

    public string $postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';

    public string $mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';

    public string $mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    public string $mongodbRestoreCommand = 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=';

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
        ];
    }

    public function mount()
    {
        if (isDev()) {
            $this->customLocation = '/data/coolify/pg-dump-all-1736245863.gz';
        }
        $this->parameters = get_route_parameters();
        $this->getContainers();
    }

    public function updatedDumpAll($value)
    {
        switch ($this->resource->getMorphClass()) {
            case \App\Models\StandaloneMariadb::class:
                if ($value === true) {
                    $this->mariadbRestoreCommand = <<<'EOD'
for pid in $(mariadb -u root -p$MARIADB_ROOT_PASSWORD -N -e "SELECT id FROM information_schema.processlist WHERE user != 'root';"); do
  mariadb -u root -p$MARIADB_ROOT_PASSWORD -e "KILL $pid" 2>/dev/null || true
done && \
mariadb -u root -p$MARIADB_ROOT_PASSWORD -N -e "SELECT CONCAT('DROP DATABASE IF EXISTS \`',schema_name,'\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');" | mariadb -u root -p$MARIADB_ROOT_PASSWORD && \
mariadb -u root -p$MARIADB_ROOT_PASSWORD -e "CREATE DATABASE IF NOT EXISTS \`default\`;" && \
(gunzip -cf $tmpPath 2>/dev/null || cat $tmpPath) | sed -e '/^CREATE DATABASE/d' -e '/^USE \`mysql\`/d' | mariadb -u root -p$MARIADB_ROOT_PASSWORD default
EOD;
                    $this->restoreCommandText = $this->mariadbRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | mariadb -u root -p$MARIADB_ROOT_PASSWORD default';
                } else {
                    $this->mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';
                }
                break;
            case \App\Models\StandaloneMysql::class:
                if ($value === true) {
                    $this->mysqlRestoreCommand = <<<'EOD'
for pid in $(mysql -u root -p$MYSQL_ROOT_PASSWORD -N -e "SELECT id FROM information_schema.processlist WHERE user != 'root';"); do
  mysql -u root -p$MYSQL_ROOT_PASSWORD -e "KILL $pid" 2>/dev/null || true
done && \
mysql -u root -p$MYSQL_ROOT_PASSWORD -N -e "SELECT CONCAT('DROP DATABASE IF EXISTS \`',schema_name,'\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');" | mysql -u root -p$MYSQL_ROOT_PASSWORD && \
mysql -u root -p$MYSQL_ROOT_PASSWORD -e "CREATE DATABASE IF NOT EXISTS \`default\`;" && \
(gunzip -cf $tmpPath 2>/dev/null || cat $tmpPath) | sed -e '/^CREATE DATABASE/d' -e '/^USE \`mysql\`/d' | mysql -u root -p$MYSQL_ROOT_PASSWORD default
EOD;
                    $this->restoreCommandText = $this->mysqlRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | mysql -u root -p$MYSQL_ROOT_PASSWORD default';
                } else {
                    $this->mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';
                }
                break;
            case \App\Models\StandalonePostgresql::class:
                if ($value === true) {
                    $this->postgresqlRestoreCommand = <<<'EOD'
psql -U $POSTGRES_USER -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" && \
psql -U $POSTGRES_USER -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U $POSTGRES_USER --if-exists {} && \
createdb -U $POSTGRES_USER postgres
EOD;
                    $this->restoreCommandText = $this->postgresqlRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | psql -U $POSTGRES_USER postgres';
                } else {
                    $this->postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';
                }
                break;
        }

    }

    public function getContainers()
    {
        $this->containers = collect();
        if (! data_get($this->parameters, 'database_uuid')) {
            abort(404);
        }
        $resource = getResourceByUuid($this->parameters['database_uuid'], data_get(auth()->user()->currentTeam(), 'id'));
        if (is_null($resource)) {
            abort(404);
        }
        $this->resource = $resource;
        $this->server = $this->resource->destination->server;
        $this->container = $this->resource->uuid;
        if (str(data_get($this, 'resource.status'))->startsWith('running')) {
            $this->containers->push($this->container);
        }

        if (
            $this->resource->getMorphClass() === \App\Models\StandaloneRedis::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneKeydb::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneDragonfly::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneClickhouse::class
        ) {
            $this->unsupported = true;
        }
    }

    public function checkFile()
    {
        if (filled($this->customLocation)) {
            try {
                $result = instant_remote_process(["ls -l {$this->customLocation}"], $this->server, throwError: false);
                if (blank($result)) {
                    $this->dispatch('error', 'The file does not exist or has been deleted.');

                    return;
                }
                $this->filename = $this->customLocation;
                $this->dispatch('success', 'The file exists.');
            } catch (\Throwable $e) {
                return handleError($e, $this);
            }
        }
    }

    public function runImport()
    {
        if ($this->filename === '') {
            $this->dispatch('error', 'Please select a file to import.');

            return;
        }
        try {
            $this->importCommands = [];
            if (filled($this->customLocation)) {
                $backupFileName = '/tmp/restore_'.$this->resource->uuid;
                $this->importCommands[] = "docker cp {$this->customLocation} {$this->container}:{$backupFileName}";
                $tmpPath = $backupFileName;
            } else {
                $backupFileName = "upload/{$this->resource->uuid}/restore";
                $path = Storage::path($backupFileName);
                if (! Storage::exists($backupFileName)) {
                    $this->dispatch('error', 'The file does not exist or has been deleted.');

                    return;
                }
                $tmpPath = '/tmp/'.basename($backupFileName).'_'.$this->resource->uuid;
                instant_scp($path, $tmpPath, $this->server);
                Storage::delete($backupFileName);
                $this->importCommands[] = "docker cp {$tmpPath} {$this->container}:{$tmpPath}";
            }

            // Copy the restore command to a script file
            $scriptPath = "/tmp/restore_{$this->resource->uuid}.sh";

            switch ($this->resource->getMorphClass()) {
                case \App\Models\StandaloneMariadb::class:
                    $restoreCommand = $this->mariadbRestoreCommand;
                    if ($this->dumpAll) {
                        $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | mariadb -u root -p\$MARIADB_ROOT_PASSWORD";
                    } else {
                        $restoreCommand .= " < {$tmpPath}";
                    }
                    break;
                case \App\Models\StandaloneMysql::class:
                    $restoreCommand = $this->mysqlRestoreCommand;
                    if ($this->dumpAll) {
                        $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | mysql -u root -p\$MYSQL_ROOT_PASSWORD";
                    } else {
                        $restoreCommand .= " < {$tmpPath}";
                    }
                    break;
                case \App\Models\StandalonePostgresql::class:
                    $restoreCommand = $this->postgresqlRestoreCommand;
                    if ($this->dumpAll) {
                        $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | psql -U \$POSTGRES_USER postgres";
                    } else {
                        $restoreCommand .= " {$tmpPath}";
                    }
                    break;
                case \App\Models\StandaloneMongodb::class:
                    $restoreCommand = $this->mongodbRestoreCommand;
                    if ($this->dumpAll === false) {
                        $restoreCommand .= "{$tmpPath}";
                    }
                    break;
            }

            $restoreCommandBase64 = base64_encode($restoreCommand);
            $this->importCommands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$scriptPath}";
            $this->importCommands[] = "chmod +x {$scriptPath}";
            $this->importCommands[] = "docker cp {$scriptPath} {$this->container}:{$scriptPath}";

            $this->importCommands[] = "docker exec {$this->container} sh -c '{$scriptPath}'";
            $this->importCommands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

            if (! empty($this->importCommands)) {
                $activity = remote_process($this->importCommands, $this->server, ignore_errors: true, callEventOnFinish: 'RestoreJobFinished', callEventData: [
                    'scriptPath' => $scriptPath,
                    'tmpPath' => $tmpPath,
                    'container' => $this->container,
                    'serverId' => $this->server->id,
                ]);
                $this->dispatch('activityMonitor', $activity->id);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->filename = null;
            $this->importCommands = [];
        }
    }
}
