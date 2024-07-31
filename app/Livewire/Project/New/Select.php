<?php

namespace App\Livewire\Project\New;

use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class Select extends Component
{
    public $current_step = 'type';

    public ?Server $server = null;

    public string $type;

    public string $server_id;

    public string $destination_uuid;

    public Collection|null|Server $allServers;

    public Collection|null|Server $servers;

    public ?Collection $standaloneDockers;

    public ?Collection $swarmDockers;

    public array $parameters;

    public Collection|array $services = [];

    public Collection|array $allServices = [];

    public bool $isDatabase = false;

    public bool $includeSwarm = true;

    public bool $loadingServices = true;

    public bool $loading = false;

    public $environments = [];

    public ?string $selectedEnvironment = null;

    public ?string $existingPostgresqlUrl = null;

    public ?string $search = null;

    protected $queryString = [
        'server_id',
        'search',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if (isDev()) {
            $this->existingPostgresqlUrl = 'postgres://coolify:password@coolify-db:5432';
        }
        $projectUuid = data_get($this->parameters, 'project_uuid');
        $this->environments = Project::whereUuid($projectUuid)->first()->environments;
        $this->selectedEnvironment = data_get($this->parameters, 'environment_name');
    }

    public function render()
    {
        return view('livewire.project.new.select');
    }

    public function updatedSelectedEnvironment()
    {
        return redirect()->route('project.resource.create', [
            'project_uuid' => $this->parameters['project_uuid'],
            'environment_name' => $this->selectedEnvironment,
        ]);
    }

    // public function addExistingPostgresql()
    // {
    //     try {
    //         instantCommand("psql {$this->existingPostgresqlUrl} -c 'SELECT 1'");
    //         $this->dispatch('success', 'Successfully connected to the database.');
    //     } catch (\Throwable $e) {
    //         return handleError($e, $this);
    //     }
    // }

    public function updatedSearch()
    {
        $this->loadServices();
    }

    public function loadServices(bool $force = false)
    {
        try {
            $this->loadingServices = true;
            if (count($this->allServices) > 0 && ! $force) {
                if (! $this->search) {
                    $this->services = $this->allServices;

                    return;
                }
                $this->services = $this->allServices->filter(function ($service, $key) {
                    $tags = collect(data_get($service, 'tags', []));

                    return str_contains(strtolower($key), strtolower($this->search)) || $tags->contains(function ($tag) {
                        return str_contains(strtolower($tag), strtolower($this->search));
                    });
                });
            } else {
                $this->search = null;
                $this->allServices = get_service_templates($force);
                $this->services = $this->allServices->filter(function ($service, $key) {
                    return str_contains(strtolower($key), strtolower($this->search));
                });
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->loadingServices = false;
        }
    }

    public function instantSave()
    {
        if ($this->includeSwarm) {
            $this->servers = $this->allServers;
        } else {
            if ($this->allServers instanceof Collection) {
                $this->servers = $this->allServers->where('settings.is_swarm_worker', false)->where('settings.is_swarm_manager', false)->where('settings.is_build_server', false);
            } else {
                $this->servers = $this->allServers;
            }
        }
    }

    public function setType(string $type)
    {
        if ($this->loading) {
            return;
        }
        $this->loading = true;
        $this->type = $type;
        switch ($type) {
            case 'postgresql':
            case 'mysql':
            case 'mariadb':
            case 'redis':
            case 'keydb':
            case 'dragonfly':
            case 'clickhouse':
            case 'mongodb':
                $this->isDatabase = true;
                $this->includeSwarm = false;
                if ($this->allServers instanceof Collection) {
                    $this->servers = $this->allServers->where('settings.is_swarm_worker', false)->where('settings.is_swarm_manager', false)->where('settings.is_build_server', false);
                } else {
                    $this->servers = $this->allServers;
                }
                break;
        }
        if (str($type)->startsWith('one-click-service') || str($type)->startsWith('docker-compose-empty')) {
            $this->isDatabase = true;
            $this->includeSwarm = false;
            if ($this->allServers instanceof Collection) {
                $this->servers = $this->allServers->where('settings.is_swarm_worker', false)->where('settings.is_swarm_manager', false)->where('settings.is_build_server', false);
            } else {
                $this->servers = $this->allServers;
            }
        }
        if ($type === 'existing-postgresql') {
            $this->current_step = $type;

            return;
        }
        if (count($this->servers) === 1) {
            $server = $this->servers->first();
            if ($server instanceof Server) {
                $this->setServer($server);
            }
        }
        if (! is_null($this->server)) {
            $foundServer = $this->servers->where('id', $this->server->id)->first();
            if ($foundServer) {
                return $this->setServer($foundServer);
            }
        }
        $this->current_step = 'servers';
    }

    public function setServer(Server $server)
    {
        $this->server_id = $server->id;
        $this->server = $server;
        $this->standaloneDockers = $server->standaloneDockers;
        $this->swarmDockers = $server->swarmDockers;
        $count = count($this->standaloneDockers) + count($this->swarmDockers);
        if ($count === 1) {
            $docker = $this->standaloneDockers->first() ?? $this->swarmDockers->first();
            if ($docker) {
                $this->setDestination($docker->uuid);
            }
        }
        $this->current_step = 'destinations';
    }

    public function setDestination(string $destination_uuid)
    {
        $this->destination_uuid = $destination_uuid;

        return redirect()->route('project.resource.create', [
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
        $this->allServers = $this->servers;
    }
}
