<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;

class Status extends Component
{
    public Server $server;

    protected $listeners = ['proxyStatusUpdated'];
    public function proxyStatusUpdated() {
        $this->server->refresh();
    }
    public function getProxyStatus()
    {
        try {
            if (data_get($this->server, 'settings.is_usable') && data_get($this->server, 'settings.is_reachable')) {
                $container = getContainerStatus(server: $this->server, container_id: 'coolify-proxy');
                $this->server->proxy->status = $container;
                $this->server->save();
                $this->emit('proxyStatusUpdated');
            }
        } catch (\Throwable $e) {
            return general_error_handler(err: $e);
        }

    }
    public function getProxyStatusWithNoti() {
        $this->emit('success', 'Refreshing proxy status.');
        $this->getProxyStatus();
    }
}
