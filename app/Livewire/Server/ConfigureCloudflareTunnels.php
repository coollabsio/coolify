<?php

namespace App\Livewire\Server;

use App\Actions\Server\ConfigureCloudflared;
use App\Models\Server;
use Livewire\Component;

class ConfigureCloudflareTunnels extends Component
{
    public $server_id;

    public string $cloudflare_token;

    public string $ssh_domain;

    public function alreadyConfigured()
    {
        try {
            $server = Server::ownedByCurrentTeam()->where('id', $this->server_id)->firstOrFail();
            $server->settings->is_cloudflare_tunnel = true;
            $server->settings->save();
            $this->dispatch('success', 'Cloudflare Tunnels configured successfully.');
            $this->dispatch('refreshServerShow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            if (str($this->ssh_domain)->contains('https://')) {
                $this->ssh_domain = str($this->ssh_domain)->replace('https://', '')->replace('http://', '')->trim();
                // remove / from the end
                $this->ssh_domain = str($this->ssh_domain)->replace('/', '');
            }
            $server = Server::ownedByCurrentTeam()->where('id', $this->server_id)->firstOrFail();
            ConfigureCloudflared::dispatch($server, $this->cloudflare_token);
            $server->settings->is_cloudflare_tunnel = true;
            $server->ip = $this->ssh_domain;
            $server->save();
            $server->settings->save();
            $this->dispatch('info', 'Cloudflare Tunnels configuration started.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.configure-cloudflare-tunnels');
    }
}
