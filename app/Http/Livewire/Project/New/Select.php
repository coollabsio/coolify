<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Server;
use Countable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class Select extends Component
{
    public $current_step = 'type';
    public ?int $server = null;
    public string $type;
    public string $server_id;
    public string $destination_uuid;
    public Countable|array|Server $servers = [];
    public Collection|array $standaloneDockers = [];
    public Collection|array $swarmDockers = [];
    public array $parameters;
    public Collection|array $services = [];
    public bool $loadingServices = true;
    public bool $loading = false;

    public ?string $existingPostgresqlUrl = null;

    protected $queryString = [
        'server',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if (isDev()) {
            $this->existingPostgresqlUrl = 'postgres://coolify:password@coolify-db:5432';
        }
    }

    // public function addExistingPostgresql()
    // {
    //     try {
    //         instantCommand("psql {$this->existingPostgresqlUrl} -c 'SELECT 1'");
    //         $this->emit('success', 'Successfully connected to the database.');
    //     } catch (\Throwable $e) {
    //         return handleError($e, $this);
    //     }
    // }

    public function loadThings()
    {
        $this->loadServices();
        $this->loadServers();
    }
    public function loadServices(bool $forceReload = false)
    {
        try {
            if ($forceReload) {
                Cache::forget('services');
            }
            $this->services = getServiceTemplates();
            $this->emit('success', 'Successfully loaded services.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->loadingServices = false;
        }
    }
    public function setType(string $type)
    {
        $this->type = $type;
        if ($this->loading) return;
        $this->loading = true;
        if ($type === "existing-postgresql") {
            $this->current_step = $type;
            return;
        }
        if (count($this->servers) === 1) {
            $server = $this->servers->first();
            $this->setServer($server);
            if (count($server->destinations()) === 1) {
                $this->setDestination($server->destinations()->first()->uuid);
            }
        }
        if (!is_null($this->server)) {
            $foundServer = $this->servers->where('id', $this->server)->first();
            if ($foundServer) {
                return $this->setServer($foundServer);
            }
        }
        $this->current_step = 'servers';
    }

    public function setServer(Server $server)
    {
        $this->server_id = $server->id;
        $this->standaloneDockers = $server->standaloneDockers;
        $this->swarmDockers = $server->swarmDockers;
        $this->current_step = 'destinations';
    }

    public function setDestination(string $destination_uuid)
    {
        $this->destination_uuid = $destination_uuid;
        redirect()->route('project.resources.new', [
            'project_uuid' => $this->parameters['project_uuid'],
            'environment_name' => $this->parameters['environment_name'],
            'type' => $this->type,
            'destination' => $this->destination_uuid,
            'server_id' => $this->server_id,
        ]);
    }

    public function loadServers()
    {
        $this->servers = Server::isUsable()->get();
    }
}
