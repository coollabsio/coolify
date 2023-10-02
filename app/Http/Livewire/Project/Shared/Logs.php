<?php

namespace App\Http\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use Livewire\Component;

class Logs extends Component
{
    public ?string $type = null;
    public Application|StandalonePostgresql|Service $resource;
    public Server $server;
    public ?string $container = null;
    public $parameters;
    public $query;
    public $status;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
            $this->status = $this->resource->status;
            $this->server = $this->resource->destination->server;
            $containers = getCurrentApplicationContainerStatus($this->server, $this->resource->id);
            if ($containers->count() > 0) {
                $this->container = data_get($containers[0], 'Names');
            }
        } else if (data_get($this->parameters, 'database_uuid')) {
            $this->type = 'database';
            $this->resource = StandalonePostgresql::where('uuid', $this->parameters['database_uuid'])->firstOrFail();
            $this->status = $this->resource->status;
            $this->server = $this->resource->destination->server;
            $this->container = $this->resource->uuid;
        } else if (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            $this->status = $this->resource->status;
            $this->server = $this->resource->server;
            $this->container = data_get($this->parameters, 'service_name') . '-' . $this->resource->uuid;
        }
    }

    public function render()
    {
        return view('livewire.project.shared.logs');
    }
}
