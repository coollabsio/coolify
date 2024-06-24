<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Support\Collection;
use Livewire\Component;

class Logs extends Component
{
    public ?string $type = null;

    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource;

    public Collection $servers;

    public Collection $containers;

    public $container = [];

    public $parameters;

    public $query;

    public $status;

    public $serviceSubType;

    public $cpu;

    public function loadContainers($server_id)
    {
        try {
            $server = $this->servers->firstWhere('id', $server_id);
            if (! $server->isFunctional()) {
                return;
            }
            if ($server->isSwarm()) {
                $containers = collect([
                    [
                        'Names' => $this->resource->uuid.'_'.$this->resource->uuid,
                    ],
                ]);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
            }
            $server->containers = $containers->sort();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        try {
            $this->containers = collect();
            $this->servers = collect();
            $this->parameters = get_route_parameters();
            $this->query = request()->query();
            if (data_get($this->parameters, 'application_uuid')) {
                $this->type = 'application';
                $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
                $this->status = $this->resource->status;
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
                $this->status = $this->resource->status;
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
            $this->containers = $this->containers->sort();
            if (data_get($this->query, 'pull_request_id')) {
                $this->containers = $this->containers->filter(function ($container) {
                    return str_contains($container, $this->query['pull_request_id']);
                });
                ray($this->containers);

            }

        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.logs');
    }
}
