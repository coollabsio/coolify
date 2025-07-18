<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;

class Show extends Component
{
    public ?Server $server = null;

    public $parameters = [];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.proxy.show');
    }
}
