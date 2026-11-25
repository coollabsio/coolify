<?php

namespace App\Livewire\Destination;

use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Index extends Component
{
    #[Locked]
    public $servers;

    #[Locked]
    public Collection $destinations;

    public function mount(): void
    {
        $this->servers = Server::isUsable()->get();
        $this->destinations = $this->servers
            ->flatMap(fn (Server $server) => $server->standaloneDockers->concat($server->swarmDockers))
            ->values();
    }

    public function render()
    {
        return view('livewire.destination.index');
    }
}
