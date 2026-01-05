<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DynamicConfigurationNavbar extends Component
{
    use AuthorizesRequests;

    public $server_id;

    public Server $server;

    public $fileName = '';

    public $value = '';

    public $newFile = false;

    public function delete(string $fileName)
    {
        $this->authorize('update', $this->server);
        $proxy_path = $this->server->proxyPath();
        $proxy_type = $this->server->proxyType();

        // Decode filename: pipes are used to encode dots for Livewire property binding
        // (e.g., 'my|service.yaml' -> 'my.service.yaml')
        // This must happen BEFORE validation because validateShellSafePath() correctly
        // rejects pipe characters as dangerous shell metacharacters
        $file = str_replace('|', '.', $fileName);

        // Validate filename to prevent command injection
        validateShellSafePath($file, 'proxy configuration filename');

        if ($proxy_type === 'CADDY' && $file === 'Caddyfile') {
            $this->dispatch('error', 'Cannot delete Caddyfile.');

            return;
        }

        $fullPath = "{$proxy_path}/dynamic/{$file}";
        $escapedPath = escapeshellarg($fullPath);
        instant_remote_process(["rm -f {$escapedPath}"], $this->server);
        if ($proxy_type === 'CADDY') {
            $this->server->reloadCaddy();
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
