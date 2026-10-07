<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Registries extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
        $this->authorize('update', $this->server);
    }

    public function render()
    {
        return view('livewire.server.registries');
    }
}
