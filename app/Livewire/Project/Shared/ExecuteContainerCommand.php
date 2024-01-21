<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Livewire\Component;

class ExecuteContainerCommand extends Component
{
    public string $command;
    public string $container;
    public $containers;
    public $parameters;
    public $resource;
    public string $type;
    public string $workDir = '';
    public Server $server;
    public $servers = [];
    public function getListeners()
    {
        return [
            "serviceStatusChanged",
        ];
    }
    public function serviceStatusChanged()
    {
        $this->getContainers();
    }
    protected $rules = [
        'server' => 'required',
        'container' => 'required',
        'command' => 'required',
        'workDir' => 'nullable',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->getContainers();
    }
    public function getContainers()
    {
        $this->containers = collect();
        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
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
            $this->server = $this->resource->destination->server;
            $this->container = $this->resource->uuid;
            // if (!str(data_get($this,'resource.status'))->startsWith('exited')) {
                $this->containers->push($this->container);
            // }
        } else if (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            $this->resource->applications()->get()->each(function ($application) {
                // if (str(data_get($application, 'status'))->contains('running')) {
                    $this->containers->push(data_get($application, 'name') . '-' . data_get($this->resource, 'uuid'));
                // }
            });
            $this->resource->databases()->get()->each(function ($database) {
                // if (str(data_get($database, 'status'))->contains('running')) {
                    $this->containers->push(data_get($database, 'name') . '-' . data_get($this->resource, 'uuid'));
                // }
            });

            $this->server = $this->resource->server;
        }
        if ($this->containers->count() > 0) {
            $this->container = $this->containers->first();
        }
    }

    public function runCommand()
    {
        $this->validate();
        try {
            // Wrap command to prevent escaped execution in the host.
            $cmd = 'sh -c "if [ -f ~/.profile ]; then . ~/.profile; fi; ' . str_replace('"', '\"', $this->command)  . '"';
            if (!empty($this->workDir)) {
                $exec = "docker exec -w {$this->workDir} {$this->container} {$cmd}";
            } else {
                $exec = "docker exec {$this->container} {$cmd}";
            }
            $activity = remote_process([$exec], $this->server, ignore_errors: true);
            $this->dispatch('newMonitorActivity', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.project.shared.execute-container-command');
    }
}
