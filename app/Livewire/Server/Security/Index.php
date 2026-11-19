<?php

namespace App\Livewire\Server\Security;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
        $this->authorize('update', $this->server);
    }

    public bool $isWaitingForConnectionCheck = false;

    #[Locked]
    public ?int $connectionCheckDeadline = null;

    #[Locked]
    public bool $wasFunctionalBeforeConnectionCheck = false;

    /**
     * A host key change queues a connection check. Poll until it changes the server state, at most for one minute.
     */
    #[On('hostKeySettingsChanged')]
    public function waitForConnectionCheck(): void
    {
        $this->authorize('update', $this->server);
        $this->refreshServer();
        $this->wasFunctionalBeforeConnectionCheck = $this->server->isFunctional();
        $this->connectionCheckDeadline = now()->addMinute()->timestamp;
        $this->isWaitingForConnectionCheck = true;
    }

    public function pollConnectionCheck(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->isWaitingForConnectionCheck) {
            return;
        }
        $this->refreshServer();
        if ($this->server->isFunctional() !== $this->wasFunctionalBeforeConnectionCheck) {
            // Load the page again: the navbar and the sidebar show the old status.
            $this->redirectRoute('server.security', ['server_uuid' => $this->server->uuid], navigate: true);

            return;
        }
        if (now()->timestamp >= $this->connectionCheckDeadline) {
            $this->isWaitingForConnectionCheck = false;
            $this->connectionCheckDeadline = null;
        }
    }

    private function refreshServer(): void
    {
        $this->server->refresh();
        $this->server->settings->refresh();
    }

    public function render(): View
    {
        return view('livewire.server.security.index');
    }
}
