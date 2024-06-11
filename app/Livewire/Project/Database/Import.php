<?php

namespace App\Livewire\Project\Database;

use App\Models\Server;
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

    public string $postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';

    public string $mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';

    public string $mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    public string $mongodbRestoreCommand = 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=';

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
            $this->resource->getMorphClass() == 'App\Models\StandaloneRedis' ||
            $this->resource->getMorphClass() == 'App\Models\StandaloneKeydb' ||
            $this->resource->getMorphClass() == 'App\Models\StandaloneDragonfly' ||
            $this->resource->getMorphClass() == 'App\Models\StandaloneClickhouse'
        ) {
            $this->unsupported = true;
        }
    }

    public function runImport()
    {

        if ($this->filename == '') {
            $this->dispatch('error', 'Please select a file to import.');

            return;
        }
        try {
            $uploadedFilename = "upload/{$this->resource->uuid}/restore";
            $path = Storage::path($uploadedFilename);
            if (! Storage::exists($uploadedFilename)) {
                $this->dispatch('error', 'The file does not exist or has been deleted.');

                return;
            }
            $tmpPath = '/tmp/'.basename($uploadedFilename);
            instant_scp($path, $tmpPath, $this->server);
            Storage::delete($uploadedFilename);
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
                case 'App\Models\StandaloneMongodb':
                    $this->importCommands[] = "docker exec {$this->container} sh -c '{$this->mongodbRestoreCommand}{$tmpPath}'";
                    $this->importCommands[] = "rm {$tmpPath}";
                    break;
            }

            $this->importCommands[] = "docker exec {$this->container} sh -c 'rm {$tmpPath}'";
            $this->importCommands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

            if (! empty($this->importCommands)) {
                $activity = remote_process($this->importCommands, $this->server, ignore_errors: true);
                $this->dispatch('activityMonitor', $activity->id);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
