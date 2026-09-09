<?php

namespace App\Livewire\Server\Sentinel;

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class Logs extends Component
{
    use AuthorizesRequests;

    public ?Server $server = null;

    public array $parameters = [];

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            handleError($e, $this);

            return;
        }

        $this->authorize('viewSentinel', $this->server);
    }

    public function enableSentinel(): void
    {
        $this->authorize('manageSentinel', $this->server);

        try {
            $this->server->refresh();
            if ($this->server->isBuildServer()) {
                $this->dispatch('error', 'Sentinel cannot be enabled on build servers.');

                return;
            }
            if ($this->server->isSwarm()) {
                $this->dispatch('error', 'Sentinel cannot be enabled on Swarm servers.');

                return;
            }
            if ($this->server->isSentinelEnabled()) {
                return;
            }

            StartSentinel::run($this->server, true);
            $this->server->refresh();
            $this->dispatch('refreshServerShow');
            $this->dispatch('success', 'Sentinel has been enabled.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.server.sentinel.logs');
    }
}
