<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Actions\Proxy\SaveConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use Livewire\Component;

class Deploy extends Component
{
    public Server $server;
    public $proxy_settings = null;
    protected $listeners = ['proxyStatusUpdated'];

    public function proxyStatusUpdated()
    {
        $this->server->refresh();
    }
    public function startProxy()
    {
        try {
            $activity = StartProxy::run($this->server);
            $this->emit('newMonitorActivity', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stop()
    {
        instant_remote_process([
            "docker rm -f coolify-proxy",
        ], $this->server);
        $this->server->proxy->status = 'exited';
        $this->server->save();
        $this->emit('proxyStatusUpdated');
    }
}
