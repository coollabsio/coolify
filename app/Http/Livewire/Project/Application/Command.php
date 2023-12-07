<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Livewire\Component;

class Command extends Component
{
    public string $command;
    public string $container;
    public string $dir;
    public $server;
    public $servers = [];

    protected $rules = [
        'server' => 'required',
        'container' => 'required',
        'command' => 'required',
    ];
    protected $validationAttributes = [
        'server' => 'server',
        'container' => 'container',
        'command' => 'command',
    ];

    public function mount()
    {

        $this->containers = collect();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
            $this->status = $this->resource->status;
            $this->server = $this->resource->destination->server;
            $containers = getCurrentApplicationContainerStatus($this->server, $this->resource->id, 0);
            if ($containers->count() > 0) {
                $containers->each(function ($container) {
                    $this->containers->push(str_replace('/', '', $container['Names']));
                });
            }
        } else if (data_get($this->parameters, 'database_uuid')) {
            $this->type = 'database';
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
            $this->status = $this->resource->status;
            $this->server = $this->resource->destination->server;
            $this->container = $this->resource->uuid;
            $this->containers->push($this->container);
        } else if (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            $service_name = data_get($this->parameters, 'service_name');
            $this->serviceSubType = $this->resource->applications()->where('name', $service_name)->first();
            if (!$this->serviceSubType) {
                $this->serviceSubType = $this->resource->databases()->where('name', $service_name)->first();
            }
            $this->status = $this->resource->status;
            $this->server = $this->resource->server;
            $this->container = data_get($this->parameters, 'service_name') . '-' . $this->resource->uuid;
            $this->containers->push($this->container);
        }
    }

    public function runCommand()
    {
        $this->validate();
        try {
            // Wrap command to prevent escaped execution in the host.
            $cmd = 'sh -c "' . str_replace('"', '\"', $this->command)  . '"';

            if (!empty($this->dir)) {
                $exec = "docker exec -w {$this->dir} {$this->container} {$cmd}";
            }
            else {
                $exec = "docker exec {$this->container} {$cmd}";
            }
            $activity = remote_process([$exec], $this->server, ignore_errors: true);
            $this->emit('newMonitorActivity', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}