<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Destinations extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public Collection $networks;

    public function mount(string $server_uuid)
    {
        try {
            $this->networks = collect();
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function add($name)
    {
        if ($this->server->isSwarm()) {
            $this->authorize('create', SwarmDocker::class);
            $found = $this->server->swarmDockers()->where('network', $name)->first();
            if ($found) {
                $this->dispatch('error', 'Network already added to this server.');

                return;
            } else {
                $destination = SwarmDocker::create([
                    'name' => $this->server->name.'-'.$name,
                    'network' => $name,
                    'server_id' => $this->server->id,
                ]);
                $this->auditDestinationCreated($destination, 'swarm');
            }
        } else {
            $this->authorize('create', StandaloneDocker::class);
            $found = $this->server->standaloneDockers()->where('network', $name)->first();
            if ($found) {
                $this->dispatch('error', 'Network already added to this server.');

                return;
            } else {
                $destination = StandaloneDocker::create([
                    'name' => $this->server->name.'-'.$name,
                    'network' => $name,
                    'server_id' => $this->server->id,
                ]);
                $this->auditDestinationCreated($destination, 'standalone');
            }
        }
    }

    public function scan()
    {
        try {
            $this->authorize('update', $this->server);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
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
            $this->dispatch('success', 'No new destinations found on this server.');

            return;
        }
        $this->dispatch('success', 'Scan done.');
    }

    private function auditDestinationCreated(StandaloneDocker|SwarmDocker $destination, string $type): void
    {
        auditLog('ui.destination.created', [
            'team_id' => $this->server->team_id,
            'destination_uuid' => $destination->uuid,
            'destination_name' => $destination->name,
            'destination_type' => $type,
            'server_uuid' => $this->server->uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.server.destinations');
    }
}
