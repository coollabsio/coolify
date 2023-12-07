<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;

class Logs extends Component
{
    public ?Server $server = null;
    public $parameters = [];
    public function mount()
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return $this->redirectRoute('server.all', navigate: true);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.server.proxy.logs');
    }
}
