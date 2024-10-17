<?php

namespace App\Livewire\Server;

use App\Livewire\BaseComponent;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Show extends BaseComponent
{
    use AuthorizesRequests;

    public Server $server;

    protected $listeners = ['refreshServerShow'];

    public function mount()
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail();
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
