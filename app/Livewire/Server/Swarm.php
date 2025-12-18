<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Swarm extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public bool $isSwarmManager;

    public bool $isSwarmWorker;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->syncData();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->authorize('update', $this->server);
            $this->server->settings->is_swarm_manager = $this->isSwarmManager;
            $this->server->settings->is_swarm_worker = $this->isSwarmWorker;
            $this->server->settings->save();
        } else {
            $this->isSwarmManager = $this->server->settings->is_swarm_manager;
            $this->isSwarmWorker = $this->server->settings->is_swarm_worker;
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Swarm settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.swarm');
    }
}
