<?php

namespace App\Livewire\Server\Security;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class TerminalAccess extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    #[Validate(['boolean'])]
    public bool $isTerminalEnabled = false;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->authorize('update', $this->server);
            $this->parameters = get_route_parameters();
            $this->syncData();

        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function toggleTerminal($password)
    {
        try {
            $this->authorize('update', $this->server);

            // Check if user is admin or owner
            if (! auth()->user()->isAdmin()) {
                throw new \Exception('Only team administrators and owners can modify terminal access.');
            }

            // Verify password
            if (! verifyPasswordConfirmation($password, $this)) {
                return;
            }

            // Toggle the terminal setting
            $this->server->settings->is_terminal_enabled = ! $this->server->settings->is_terminal_enabled;
            $this->server->settings->save();

            // Update the local property
            $this->isTerminalEnabled = $this->server->settings->is_terminal_enabled;

            $status = $this->isTerminalEnabled ? 'enabled' : 'disabled';
            $this->dispatch('success', "Terminal access has been {$status}.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->authorize('update', $this->server);
            $this->validate();
            // No other fields to sync for terminal access
        } else {
            $this->isTerminalEnabled = $this->server->settings->is_terminal_enabled;
        }
    }

    public function render()
    {
        return view('livewire.server.security.terminal-access');
    }
}
