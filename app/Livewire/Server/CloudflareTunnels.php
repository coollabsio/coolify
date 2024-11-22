<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CloudflareTunnels extends Component
{
    public Server $server;

    #[Validate(['required', 'boolean'])]
    public bool $isCloudflareTunnelsEnabled;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            if ($this->server->isLocalhost()) {
                return redirect()->route('server.show', ['server_uuid' => $server_uuid]);
            }
            $this->isCloudflareTunnelsEnabled = $this->server->settings->is_cloudflare_tunnel;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->validate();
            $this->server->settings->is_cloudflare_tunnel = $this->isCloudflareTunnelsEnabled;
            $this->server->settings->save();
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function manualCloudflareConfig()
    {
        $this->isCloudflareTunnelsEnabled = true;
        $this->server->settings->is_cloudflare_tunnel = true;
        $this->server->settings->save();
        $this->server->refresh();
        $this->dispatch('success', 'Cloudflare Tunnels enabled.');
    }

    public function render()
    {
        return view('livewire.server.cloudflare-tunnels');
    }
}
