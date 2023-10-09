<?php

namespace App\Http\Livewire\Server\Destination;

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
            $this->server = Server::ownedByCurrentTeam(['name', 'proxy'])->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.all');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.server.destination.show');
    }
}
