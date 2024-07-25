<?php

namespace App\Livewire\Destination\New;

use App\Models\Server;
use App\Models\StandaloneDocker as ModelsStandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Docker extends Component
{
    public string $name;

    public string $network;

    public ?Collection $servers = null;

    public Server $server;

    public ?int $server_id = null;

    public bool $is_swarm = false;

    protected $rules = [
        'name' => 'required|string',
        'network' => 'required|string',
        'server_id' => 'required|integer',
        'is_swarm' => 'boolean',
    ];

    protected $validationAttributes = [
        'name' => 'name',
        'network' => 'network',
        'server_id' => 'server',
        'is_swarm' => 'swarm',
    ];

    public function mount()
    {
        if (is_null($this->servers)) {
            $this->servers = Server::isReachable()->get();
        }
        if (request()->query('server_id')) {
            $this->server_id = request()->query('server_id');
        } else {
            if ($this->servers->count() > 0) {
                $this->server_id = $this->servers->first()->id;
            }
        }
        if (request()->query('network_name')) {
            $this->network = request()->query('network_name');
        } else {
            $this->network = new Cuid2;
        }
        if ($this->servers->count() > 0) {
            $this->name = str("{$this->servers->first()->name}-{$this->network}")->kebab();
        }
    }

    public function generate_name()
    {
        $this->server = Server::find($this->server_id);
        $this->name = str("{$this->server->name}-{$this->network}")->kebab();
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->server = Server::find($this->server_id);
            if ($this->is_swarm) {
                $found = $this->server->swarmDockers()->where('network', $this->network)->first();
                if ($found) {
                    $this->dispatch('error', 'Network already added to this server.');

                    return;
                } else {
                    $docker = SwarmDocker::create([
                        'name' => $this->name,
                        'network' => $this->network,
                        'server_id' => $this->server_id,
                    ]);
                }
            } else {
                $found = $this->server->standaloneDockers()->where('network', $this->network)->first();
                if ($found) {
                    $this->dispatch('error', 'Network already added to this server.');

                    return;
                } else {
                    $docker = ModelsStandaloneDocker::create([
                        'name' => $this->name,
                        'network' => $this->network,
                        'server_id' => $this->server_id,
                    ]);
                }
            }
            $this->createNetworkAndAttachToProxy();

            return redirect()->route('destination.show', $docker->uuid);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function createNetworkAndAttachToProxy()
    {
        $connectProxyToDockerNetworks = connectProxyToNetworks($this->server);
        instant_remote_process($connectProxyToDockerNetworks, $this->server, false);
    }
}
