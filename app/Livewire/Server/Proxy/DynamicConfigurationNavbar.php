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
        $proxy_path = get_proxy_path();
        $file = str_replace('|', '.', $fileName);
        instant_remote_process(["rm -f {$proxy_path}/dynamic/{$file}"], $server);
        $this->dispatch('success', 'File deleted.');
        $this->dispatch('loadDynamicConfigurations');
        $this->dispatch('refresh');
    }
    public function render()
    {
        return view('livewire.server.proxy.dynamic-configuration-navbar');
    }
}
