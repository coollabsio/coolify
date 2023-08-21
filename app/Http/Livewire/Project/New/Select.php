<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Server;
use Livewire\Component;

class Select extends Component
{
    public $current_step = 'type';
    public string $type;
    public string $server_id;
    public string $destination_uuid;
    public $servers = [];
    public $destinations = [];
    public array $parameters;

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function set_type(string $type)
    {
        $this->type = $type;
        $this->current_step = 'servers';
    }

    public function set_server(Server $server)
    {
        $this->server_id = $server->id;
        $this->destinations = $server->destinations();
        $this->current_step = 'destinations';
    }

    public function set_destination(string $destination_uuid)
    {
        $this->destination_uuid = $destination_uuid;
        redirect()->route('project.resources.new', [
            'project_uuid' => $this->parameters['project_uuid'],
            'environment_name' => $this->parameters['environment_name'],
            'type' => $this->type,
            'destination' => $this->destination_uuid,
        ]);
    }

    public function load_servers()
    {
        $this->servers = Server::isUsable()->get();
    }
}
