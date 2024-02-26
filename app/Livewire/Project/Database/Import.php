<?php

namespace App\Livewire\Project\Database;

use Exception;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\Server;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Support\Facades\Storage;

class Import extends Component
{
    use WithFileUploads;

    public $file;
    public $resource;
    public $parameters;
    public $containers;
    public bool $validated = true;
    public bool $scpInProgress = false;
    public bool $importRunning = false;
    public string $validationMsg = '';
    public Server $server;
    public string $container;
    public array $importCommands = [];
    public string $postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';
    public string $mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';
    public string $mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    public function getListeners()
    {
        $userId = auth()->user()->id;
        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
        ];
    }
    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->getContainers();
    }

    public function getContainers()
    {
        $this->containers = collect();
        if (!data_get($this->parameters, 'database_uuid')) {
            abort(404);
        }

        $resource = StandalonePostgresql::where('uuid', $this->parameters['database_uuid'])->first();
        if (is_null($resource)) {
            $resource = StandaloneRedis::where('uuid', $this->parameters['database_uuid'])->first();
            if (is_null($resource)) {
                $resource = StandaloneMongodb::where('uuid', $this->parameters['database_uuid'])->first();
                if (is_null($resource)) {
                    $resource = StandaloneMysql::where('uuid', $this->parameters['database_uuid'])->first();
                    if (is_null($resource)) {
                        $resource = StandaloneMariadb::where('uuid', $this->parameters['database_uuid'])->first();
                        if (is_null($resource)) {
                            abort(404);
                        }
                    }
                }
            }
        }
        $this->resource = $resource;
        $this->server = $this->resource->destination->server;
        $this->container = $this->resource->uuid;
        if (str(data_get($this, 'resource.status'))->startsWith('running')) {
            $this->containers->push($this->container);
        }

        if ($this->containers->count() > 1) {
            $this->validated = false;
            $this->validationMsg = 'The database service has more than one container running. Cannot import.';
        }

        if (
            $this->resource->getMorphClass() == 'App\Models\StandaloneRedis'
            || $this->resource->getMorphClass() == 'App\Models\StandaloneMongodb'
        ) {
            $this->validated = false;
            $this->validationMsg = 'This database type is not currently supported.';
        }
    }

    public function runImport()
    {
        $this->validate([
            'file' => 'required|file|max:102400'
        ]);

        $this->importRunning = true;
        $this->scpInProgress = true;

        try {
            $uploadedFilename = $this->file->store('backup-import');
            $path = Storage::path($uploadedFilename);
            $tmpPath = '/tmp/' . basename($uploadedFilename);

            // SCP the backup file to the server.
            instant_scp($path, $tmpPath, $this->server);
            $this->scpInProgress = false;

            $this->importCommands[] = "docker cp {$tmpPath} {$this->container}:{$tmpPath}";

            switch ($this->resource->getMorphClass()) {
                case 'App\Models\StandaloneMariadb':
                    $this->importCommands[] = "docker exec {$this->container} sh -c '{$this->mariadbRestoreCommand} < {$tmpPath}'";
                    $this->importCommands[] = "rm {$tmpPath}";
                    break;
                case 'App\Models\StandaloneMysql':
                    $this->importCommands[] = "docker exec {$this->container} sh -c '{$this->mysqlRestoreCommand} < {$tmpPath}'";
                    $this->importCommands[] = "rm {$tmpPath}";
                    break;
                case 'App\Models\StandalonePostgresql':
                    $this->importCommands[] = "docker exec {$this->container} sh -c '{$this->postgresqlRestoreCommand} {$tmpPath}'";
                    $this->importCommands[] = "rm {$tmpPath}";
                    break;
            }

            $this->importCommands[] = "docker exec {$this->container} sh -c 'rm {$tmpPath}'";
            $this->importCommands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

            if (!empty($this->importCommands)) {
                $activity = remote_process($this->importCommands, $this->server, ignore_errors: true);
                $this->dispatch('activityMonitor', $activity->id);
            }
        } catch (\Throwable $e) {
            $this->validated = false;
            $this->validationMsg = $e->getMessage();
        }
    }
}
