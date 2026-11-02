<?php

namespace App\Livewire\Server\Security;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
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

    public function render(): View
    {
        return view('livewire.server.security.index');
    }
}
