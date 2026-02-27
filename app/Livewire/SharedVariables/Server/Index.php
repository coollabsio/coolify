<?php

namespace App\Livewire\SharedVariables\Server;

use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Collection $servers;

    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeam()->get();
    }

    public function render()
    {
        return view('livewire.shared-variables.server.index');
    }
}
