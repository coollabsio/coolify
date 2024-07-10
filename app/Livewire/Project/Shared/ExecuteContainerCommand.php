<?php

namespace App\Livewire\Project\Shared;

use App\Actions\Server\RunCommand;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;
use Livewire\Component;

class ExecuteContainerCommand extends Component
{
    public string $command;

    public string $container;

    public Collection $containers;

    public $parameters;

    public $resource;

    public string $type;

    public string $workDir = '';

    public Server $server;

    public Collection $servers;

    protected $rules = [
        'server' => 'required',
        'container' => 'required',
        'command' => 'required',
        'workDir' => 'nullable',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->containers = collect();
        $this->servers = collect();
        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            foreach ($this->resource->additional_servers as $server) {
                if ($server->isFunctional()) {
                    $this->servers = $this->servers->push($server);
                }
            }
        } elseif (data_get($this->parameters, 'database_uuid')) {
            $this->type = 'database';
            $resource = getResourceByUuid($this->parameters['database_uuid'], data_get(auth()->user()->currentTeam(), 'id'));
            if (is_null($resource)) {
                abort(404);
            }
            $this->resource = $resource;
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            $this->container = $this->resource->uuid;
            $this->containers->push($this->container);
        } elseif (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            $this->resource->applications()->get()->each(function ($application) {
                $this->containers->push(data_get($application, 'name').'-'.data_get($this->resource, 'uuid'));
            });
            $this->resource->databases()->get()->each(function ($database) {
                $this->containers->push(data_get($database, 'name').'-'.data_get($this->resource, 'uuid'));
            });
            if ($this->resource->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->server);
            }
        }
        if ($this->containers->count() > 0) {
            $this->container = $this->containers->first();
        }
    }

    public function loadContainers()
    {
        foreach ($this->servers as $server) {
            if (data_get($this->parameters, 'application_uuid')) {
                if ($server->isSwarm()) {
                    $containers = collect([
                        [
                            'Names' => $this->resource->uuid.'_'.$this->resource->uuid,
                        ],
                    ]);
                } else {
                    $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
                }
                foreach ($containers as $container) {
                    $payload = [
                        'server' => $server,
                        'container' => $container,
                    ];
                    $this->containers = $this->containers->push($payload);
                }
            }
        }
        if ($this->containers->count() > 0) {
            if (data_get($this->parameters, 'application_uuid')) {
                $this->container = data_get($this->containers->first(), 'container.Names');
            } elseif (data_get($this->parameters, 'database_uuid')) {
                $this->container = $this->containers->first();
            } elseif (data_get($this->parameters, 'service_uuid')) {
                $this->container = $this->containers->first();
            }
        }
    }

    public function runCommand()
    {
        try {
            if (data_get($this->parameters, 'application_uuid')) {
                $container = $this->containers->where('container.Names', $this->container)->first();
                $container_name = data_get($container, 'container.Names');
                if (is_null($container)) {
                    throw new \RuntimeException('Container not found.');
                }
                $server = data_get($container, 'server');
            } else {
                $container_name = $this->container;
                $server = $this->servers->first();
            }
            if ($server->isForceDisabled()) {
                throw new \RuntimeException('Server is disabled.');
            }
            $cmd = "sh -c 'if [ -f ~/.profile ]; then . ~/.profile; fi; ".str_replace("'", "'\''", $this->command)."'";
            if (! empty($this->workDir)) {
                $exec = "docker exec -w {$this->workDir} {$container_name} {$cmd}";
            } else {
                $exec = "docker exec {$container_name} {$cmd}";
            }
            $activity = RunCommand::run(server: $server, command: $exec);
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.execute-container-command');
    }
}
