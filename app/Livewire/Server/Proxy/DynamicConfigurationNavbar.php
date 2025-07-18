<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;

class DynamicConfigurationNavbar extends Component
{
    public $server_id;

    public $fileName = '';

    public $value = '';

    public $newFile = false;

    public function delete(string $fileName)
    {
        $server = Server::ownedByCurrentTeam()->whereId($this->server_id)->first();
        $proxy_path = $server->proxyPath();
        $proxy_type = $server->proxyType();
        $file = str_replace('|', '.', $fileName);
        if ($proxy_type === 'CADDY' && $file === 'Caddyfile') {
            $this->dispatch('error', 'Cannot delete Caddyfile.');

            return;
        }
        instant_remote_process(["rm -f {$proxy_path}/dynamic/{$file}"], $server);
        if ($proxy_type === 'CADDY') {
            $server->reloadCaddy();
        }
        $this->dispatch('success', 'File deleted.');
        $this->dispatch('loadDynamicConfigurations');
        $this->dispatch('refresh');
    }

    public function render()
    {
        return view('livewire.server.proxy.dynamic-configuration-navbar');
    }
}
