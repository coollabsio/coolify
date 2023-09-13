<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;

class Status extends Component
{
    public Server $server;

    protected $listeners = ['proxyStatusUpdated'];
    public function proxyStatusUpdated()
    {
        $this->server->refresh();
    }
    public function getProxyStatus()
    {
        try {
            if ($this->server->isFunctional()) {
                $container = getContainerStatus(server: $this->server, container_id: 'coolify-proxy');
                $this->server->proxy->status = $container;
                $this->server->save();
                $this->emit('proxyStatusUpdated');
            }
        } catch (\Throwable $e) {
            return general_error_handler(err: $e);
        }
    }
    public function getProxyStatusWithNoti()
    {
        $this->emit('success', 'Refreshed proxy status.');
        $this->getProxyStatus();
    }
}
