<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Jobs\ContainerStatusJob;
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
                dispatch_sync(new ContainerStatusJob($this->server));
                $this->emit('proxyStatusUpdated');
            }
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
    public function getProxyStatusWithNoti()
    {
        $this->emit('success', 'Refreshed proxy status.');
        $this->getProxyStatus();
    }
}
