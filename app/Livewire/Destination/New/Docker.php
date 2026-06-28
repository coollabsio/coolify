<?php

namespace App\Livewire\Destination\New;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Docker extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public $servers;

    #[Locked]
    public Server $selectedServer;

    #[Validate(['required', 'string'])]
    public string $name;

    #[Validate(['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'])]
    public string $network;

    #[Validate(['required', 'string'])]
    public string $serverId;

    #[Validate(['required', 'boolean'])]
    public bool $isSwarm = false;

    public function mount(?string $server_id = null): void
    {
        $this->network = (string) new Cuid2;
        $this->servers = Server::isUsable()->get();

        if (filled($server_id)) {
            $this->selectedServer = Server::ownedByCurrentTeam()->whereKey($server_id)->firstOrFail();

            if (! $this->servers->contains('id', $this->selectedServer->id)) {
                $this->servers->push($this->selectedServer);
            }

            $this->serverId = (string) $this->selectedServer->id;
        } else {
            $foundServer = $this->servers->first();
            if (! $foundServer) {
                throw new \Exception('Server not found.');
            }
            $this->selectedServer = $foundServer;
            $this->serverId = (string) $this->selectedServer->id;
        }
        $this->generateName();
    }

    public function updatedServerId(): void
    {
        $this->selectedServer = $this->servers->find($this->serverId);
        if (! $this->selectedServer) {
            throw new \Exception('Server not found.');
        }
        $this->generateName();
    }

    public function generateName(): void
    {
        $name = data_get($this->selectedServer, 'name', new Cuid2);
        $this->name = str("{$name}-{$this->network}")->kebab();
    }

    public function submit(): mixed
    {
        try {
            $this->authorize('create', $this->isSwarm ? SwarmDocker::class : StandaloneDocker::class);
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
            redirectRoute($this, 'destination.show', [$docker->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
