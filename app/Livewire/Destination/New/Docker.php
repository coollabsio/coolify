<?php

namespace App\Livewire\Destination\New;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Docker extends Component
{
    #[Locked]
    public $servers;

    #[Locked]
    public Server $selectedServer;

    #[Validate(['required', 'string'])]
    public string $name;

    #[Validate(['required', 'string'])]
    public string $network;

    #[Validate(['required', 'string'])]
    public string $serverId;

    #[Validate(['required', 'boolean'])]
    public bool $isSwarm = false;

    public function mount(?string $server_id = null)
    {
        $this->network = new Cuid2;
        $this->servers = Server::isUsable()->get();
        if ($server_id) {
            $this->selectedServer = $this->servers->find($server_id) ?: $this->servers->first();
            $this->serverId = $this->selectedServer->id;
        } else {
            $this->selectedServer = $this->servers->first();
            $this->serverId = $this->selectedServer->id;
        }
        $this->generateName();
    }

    public function updatedServerId()
    {
        $this->selectedServer = $this->servers->find($this->serverId);
        $this->generateName();
    }

    public function generateName()
    {
        $name = data_get($this->selectedServer, 'name', new Cuid2);
        $this->name = str("{$name}-{$this->network}")->kebab();
    }

    public function submit()
    {
        try {
            $this->validate();
            if ($this->isSwarm) {
                $found = $this->selectedServer->swarmDockers()->where('network', $this->network)->first();
                if ($found) {
                    throw new \Exception('Network already added to this server.');
                } else {
                    $docker = SwarmDocker::create([
                        'name' => $this->name,
                        'network' => $this->network,
                        'server_id' => $this->selectedServer->id,
                    ]);
                }
            } else {
                $found = $this->selectedServer->standaloneDockers()->where('network', $this->network)->first();
                if ($found) {
                    throw new \Exception('Network already added to this server.');
                } else {
                    $docker = StandaloneDocker::create([
                        'name' => $this->name,
                        'network' => $this->network,
                        'server_id' => $this->selectedServer->id,
                    ]);
                }
            }
            $connectProxyToDockerNetworks = connectProxyToNetworks($this->selectedServer);
            instant_remote_process($connectProxyToDockerNetworks, $this->selectedServer, false);
            $this->dispatch('reloadWindow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
