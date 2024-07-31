<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Resources extends Component
{
    use AuthorizesRequests;

    public ?Server $server = null;

    public $parameters = [];

    public Collection $unmanagedContainers;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'refreshStatus',
        ];
    }

    public function startUnmanaged($id)
    {
        $this->server->startUnmanaged($id);
        $this->dispatch('success', 'Container started.');
        $this->loadUnmanagedContainers();
    }

    public function restartUnmanaged($id)
    {
        $this->server->restartUnmanaged($id);
        $this->dispatch('success', 'Container restarted.');
        $this->loadUnmanagedContainers();
    }

    public function stopUnmanaged($id)
    {
        $this->server->stopUnmanaged($id);
        $this->dispatch('success', 'Container stopped.');
        $this->loadUnmanagedContainers();
    }

    public function refreshStatus()
    {
        $this->server->refresh();
        $this->loadUnmanagedContainers();
        $this->dispatch('success', 'Resource statuses refreshed.');
    }

    public function loadUnmanagedContainers()
    {
        try {
            $this->unmanagedContainers = $this->server->loadUnmanagedContainers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->unmanagedContainers = collect();
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.resources');
    }
}
