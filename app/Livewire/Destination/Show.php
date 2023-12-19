<?php

namespace App\Livewire\Destination;

use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class Show extends Component
{
    public Server $server;
    public Collection|array $networks = [];

    public function scan()
    {
        if ($this->server->isSwarm()) {
            $alreadyAddedNetworks = $this->server->swarmDockers;
        } else {
            $alreadyAddedNetworks = $this->server->standaloneDockers;
        }
        $networks = instant_remote_process(['docker network ls --format "{{json .}}"'], $this->server, false);
        $this->networks = format_docker_command_output_to_json($networks)->filter(function ($network) {
            return $network['Name'] !== 'bridge' && $network['Name'] !== 'host' && $network['Name'] !== 'none';
        })->filter(function ($network) use ($alreadyAddedNetworks) {
            return !$alreadyAddedNetworks->contains('network', $network['Name']);
        });
        if ($this->networks->count() === 0) {
            $this->dispatch('success', 'No new networks found.');
        }
    }
}
