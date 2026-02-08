<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Livewire\Component;

class EnvironmentVariables extends Component
{
    public Server $server;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function render()
    {
        return view('livewire.server.environment-variables');
    }
}
