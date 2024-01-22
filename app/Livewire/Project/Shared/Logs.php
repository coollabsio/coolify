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

class Logs extends Component
{
    public ?string $type = null;
    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb $resource;
    public Server $server;
    public $container = [];
    public $containers;
    public $parameters;
    public $query;
    public $status;
    public $serviceSubType;

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
            if ($this->server->isSwarm()) {
                $containers = collect([
                    [
                        'Names' => $this->resource->uuid . '_' . $this->resource->uuid,
                    ]
                ]);
            } else {
                $containers = getCurrentApplicationContainerStatus($this->server, $this->resource->id, 0);
            }
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
            // if (str(data_get($this, 'resource.status'))->startsWith('running')) {
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
    }

    public function render()
    {
        return view('livewire.project.shared.logs');
    }
}
