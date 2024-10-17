<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Livewire\Component;

class CloudflareTunnels extends Component
{
    public Server $server;

    protected $rules = [
        'server.settings.is_cloudflare_tunnel' => 'required|boolean',
    ];

    protected $validationAttributes = [
        'server.settings.is_cloudflare_tunnel' => 'Cloudflare Tunnel',
    ];

    public function instantSave()
    {
        try {
            $this->validate();
            $this->server->settings->save();
            $this->dispatch('success', 'Server updated.');
            $this->dispatch('refreshServerShow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }


    public function manualCloudflareConfig()
    {
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
