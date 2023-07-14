<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use Livewire\Component;
use Str;

class Deploy extends Component
{
    public Server $server;
    public $proxy_settings = null;
    protected $listeners = ['proxyStatusUpdated'];
    public function proxyStatusUpdated()
    {
        $this->server->refresh();
    }
    public function deploy()
    {
        if (
            $this->server->proxy->last_applied_settings &&
            $this->server->proxy->last_saved_settings !== $this->server->proxy->last_applied_settings
        ) {
            $this->saveConfiguration($this->server);
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
    private function saveConfiguration(Server $server)
    {
        $this->emit('saveConfiguration', $server);
    }
}
