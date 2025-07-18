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

    public array $serverContainers = [];

    public $container = [];

    public $parameters;

    public $query;

    public $status;

    public $serviceSubType;

    public $cpu;

    public bool $containersLoaded = false;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
        ];
    }

    public function loadAllContainers()
    {
        try {
            foreach ($this->servers as $server) {
                $this->serverContainers[$server->id] = $this->getContainersForServer($server);
            }
            $this->containersLoaded = true;
        } catch (\Exception $e) {
            $this->containersLoaded = true; // Set to true to stop loading spinner

            return handleError($e, $this);
        }
    }

    private function getContainersForServer($server)
    {
        if (! $server->isFunctional()) {
            return [];
        }

        try {
            if ($server->isSwarm()) {
                $containers = collect([
                    [
                        'ID' => $this->resource->uuid,
                        'Names' => $this->resource->uuid.'_'.$this->resource->uuid,
                    ],
                ]);

                return $containers->toArray();
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
                if ($containers && $containers->count() > 0) {
                    return $containers->sort()->toArray();
                }

                return [];
            }
        } catch (\Exception $e) {
            // Log error but don't fail the entire operation
            ray("Error loading containers for server {$server->name}: ".$e->getMessage());

            return [];
        }
    }

    public function mount()
    {
        try {
            $this->containers = collect();
            $this->servers = collect();
            $this->serverContainers = [];
            $this->parameters = get_route_parameters();
            $this->query = request()->query();
            if (data_get($this->parameters, 'application_uuid')) {
                $this->type = 'application';
                $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
                $this->status = $this->resource->status;
                if ($this->resource->destination->server->isFunctional()) {
                    $server = $this->resource->destination->server;
                    $this->servers = $this->servers->push($server);
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
                    $server = $this->resource->destination->server;
                    $this->servers = $this->servers->push($server);
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
                    $server = $this->resource->server;
                    $this->servers = $this->servers->push($server);
                }
            }
            $this->containers = $this->containers->sort();
            if (data_get($this->query, 'pull_request_id')) {
                $this->containers = $this->containers->filter(function ($container) {
                    return str_contains($container, $this->query['pull_request_id']);
                });
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
