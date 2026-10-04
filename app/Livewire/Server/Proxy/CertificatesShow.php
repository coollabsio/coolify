<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class CertificatesShow extends Component
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
        return view('livewire.server.proxy.certificates-show');
    }
}
