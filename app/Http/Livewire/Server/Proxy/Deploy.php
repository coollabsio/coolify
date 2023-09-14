<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Actions\Proxy\SaveConfigurationSync;
use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use Livewire\Component;

class Deploy extends Component
{
    public Server $server;
    public $proxy_settings = null;
    protected $listeners = ['proxyStatusUpdated'];

    public function proxyStatusUpdated() {
        $this->server->refresh();
    }
    public function startProxy()
    {
        if (
            $this->server->proxy->last_applied_settings &&
            $this->server->proxy->last_saved_settings !== $this->server->proxy->last_applied_settings
        ) {
            resolve(SaveConfigurationSync::class)($this->server, $this->proxy_settings);
        }

        $activity = resolve(StartProxy::class)($this->server);
        $this->emit('newMonitorActivity', $activity->id);
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
