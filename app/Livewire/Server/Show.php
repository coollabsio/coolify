<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters;

    protected $listeners = ['refreshServerShow'];

    public function mount()
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshServerShow()
    {
        $this->server->refresh();
        $this->dispatch('$refresh');
    }

    public function submit()
    {
        $this->dispatch('serverRefresh', false);
    }

    public function render()
    {
        return view('livewire.server.show');
    }
}
