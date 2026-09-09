<?php

namespace App\Livewire\Server\Analytics;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public function mount(string $server_uuid): void
    {
        $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('view', $this->server);
    }

    public function render(): View
    {
        return view('livewire.server.analytics.show');
    }
}
