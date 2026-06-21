<?php

namespace App\Livewire\Server\Sentinel;

use App\Models\Server;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    public ?Server $server = null;

    public array $parameters = [];

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.server.sentinel.show');
    }
}
