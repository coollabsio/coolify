<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Livewire\Component;

class DockerRegistries extends Component
{
    public function render()
    {
        $servers = Server::ownedByCurrentTeamCached()
            ->filter(fn (Server $server) => auth()->user()->can('update', $server))
            ->values();

        return view('livewire.server.docker-registries', [
            'servers' => $servers,
        ]);
    }
}
