<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Countable;
use Illuminate\Support\Collection;
use Livewire\Component;
use Route;

class Select extends Component
{
    public $current_step = 'type';
    public ?int $server = null;
    public string $type;
    public string $server_id;
    public string $destination_uuid;
    public Countable|array|Server $servers;
    public Collection|array $standaloneDockers = [];
    public Collection|array $swarmDockers = [];
    public array $parameters;

    protected $queryString = [
        'server',
    ];
    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function set_type(string $type)
    {
        $this->type = $type;
        if (count($this->servers) === 1) {
            $server = $this->servers->first();
            $this->set_server($server);
            if (count($server->destinations()) === 1) {
                $this->set_destination($server->destinations()->first()->uuid);
            }
        }
        if (!is_null($this->server)) {
            $foundServer = $this->servers->where('id', $this->server)->first();
            if ($foundServer) {
                return $this->set_server($foundServer);
            }
        }
        $this->current_step = 'servers';
    }

    public function set_server(Server $server)
    {
        $this->server_id = $server->id;
        $this->standaloneDockers = $server->standaloneDockers;
        $this->swarmDockers = $server->swarmDockers;
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
