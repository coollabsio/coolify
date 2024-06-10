<?php

namespace App\Livewire\Destination;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use Livewire\Component;

class Show extends Component
{
    public Server $server;

    public Collection|array $networks = [];

    private function createNetworkAndAttachToProxy()
    {
        $connectProxyToDockerNetworks = connectProxyToNetworks($this->server);
        instant_remote_process($connectProxyToDockerNetworks, $this->server, false);
    }

    public function add($name)
    {
        if ($this->server->isSwarm()) {
            $found = $this->server->swarmDockers()->where('network', $name)->first();
            if ($found) {
                $this->dispatch('error', 'Network already added to this server.');

                return;
            } else {
                SwarmDocker::create([
                    'name' => $this->server->name.'-'.$name,
                    'network' => $this->name,
                    'server_id' => $this->server->id,
                ]);
            }
        } else {
            $found = $this->server->standaloneDockers()->where('network', $name)->first();
            if ($found) {
                $this->dispatch('error', 'Network already added to this server.');

                return;
            } else {
                StandaloneDocker::create([
                    'name' => $this->server->name.'-'.$name,
                    'network' => $name,
                    'server_id' => $this->server->id,
                ]);
            }
            $this->createNetworkAndAttachToProxy();
        }
    }

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
            return ! $alreadyAddedNetworks->contains('network', $network['Name']);
        });
        if ($this->networks->count() === 0) {
            $this->dispatch('success', 'No new networks found.');

            return;
        }
        $this->dispatch('success', 'Scan done.');
    }
}
