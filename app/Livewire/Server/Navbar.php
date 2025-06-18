<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Livewire\Component;

class Navbar extends Component
{
    public Server $server;

    public bool $isChecking = false;

    public ?string $currentRoute = null;

    public bool $traefikDashboardAvailable = false;

    public ?string $serverIp = null;

    public ?string $proxyStatus = 'unknown';

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            'refreshServerShow' => '$refresh',
            "echo-private:team.{$teamId},ProxyStatusChangedUI" => 'showNotification',
        ];
    }

    public function mount(Server $server)
    {
        $this->server = $server;
        $this->currentRoute = request()->route()->getName();
        $this->serverIp = $this->server->id === 0 ? base_ip() : $this->server->ip;
        $this->proxyStatus = $this->server->proxy->status ?? 'unknown';
        $this->loadProxyConfiguration();
    }

    public function loadProxyConfiguration()
    {
        try {
            if ($this->proxyStatus === 'running') {
                $this->traefikDashboardAvailable = ProxyDashboardCacheService::isTraefikDashboardAvailableFromCache($this->server);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restart()
    {
        try {
            RestartProxyJob::dispatch($this->server);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkProxy()
    {
        try {
            CheckProxy::run($this->server, true);
            $this->dispatch('startProxy')->self();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function startProxy()
    {
        try {
            $activity = StartProxy::run($this->server, force: true);
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stop(bool $forceStop = true)
    {
        try {
            StopProxy::dispatch($this->server, $forceStop);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkProxyStatus()
    {
        if ($this->isChecking) {
            return;
        }

        try {
            $this->isChecking = true;
            CheckProxy::run($this->server, true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->isChecking = false;
            $this->showNotification();
        }
    }

    public function showNotification()
    {
        $this->proxyStatus = $this->server->proxy->status ?? 'unknown';
        $forceStop = $this->server->proxy->force_stop ?? false;

        switch ($this->proxyStatus) {
            case 'running':
                $this->loadProxyConfiguration();
                break;
            case 'restarting':
                $this->dispatch('info', 'Initiating proxy restart.');
                break;
            default:
                break;
        }

    }

    public function render()
    {
        return view('livewire.server.navbar');
    }
}
