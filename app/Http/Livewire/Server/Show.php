<?php

namespace App\Http\Livewire\Server;

use App\Models\Server;
use Livewire\Component;

class Show extends Component
{
    public ?Server $server = null;
    public function mount()
    {
        $this->server = Server::ownedByCurrentTeam(['name', 'description', 'ip', 'port', 'user', 'proxy'])->whereUuid(request()->server_uuid)->firstOrFail();
    }
    public function render()
    {
        return view('livewire.server.show');
    }
}
