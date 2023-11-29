<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use Livewire\Component;

class Deploy extends Component
{
    public Server $server;
    public bool $traefikDashboardAvailable = false;
    public ?string $currentRoute = null;
    public ?string $serverIp = null;

    protected $listeners = ['proxyStatusUpdated', 'traefikDashboardAvailable', 'serverRefresh' => 'proxyStatusUpdated', "checkProxy", "startProxy"];

    public function mount()
    {
        if ($this->server->id === 0) {
            $this->serverIp = base_ip();
        } else {
            $this->serverIp = $this->server->ip;
        }
        $this->currentRoute = request()->route()->getName();
    }
    public function traefikDashboardAvailable(bool $data)
    {
        $this->traefikDashboardAvailable = $data;
    }
    public function proxyStatusUpdated()
    {
        $this->server->refresh();
    }
    public function ip()
    {
    }
    public function checkProxy()
    {
        try {
            CheckProxy::run($this->server, true);
            $this->emit('startProxyPolling');
            $this->emit('proxyChecked');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
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
        try {
            if ($this->server->isSwarm()) {
                instant_remote_process([
                    "docker service rm coolify-proxy_traefik",
                ], $this->server);
                $this->server->proxy->status = 'exited';
                $this->server->save();
                $this->emit('proxyStatusUpdated');
            } else {
                instant_remote_process([
                    "docker rm -f coolify-proxy",
                ], $this->server);
                $this->server->proxy->status = 'exited';
                $this->server->save();
                $this->emit('proxyStatusUpdated');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

    }
}
