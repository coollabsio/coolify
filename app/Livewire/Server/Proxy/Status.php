<?php

namespace App\Livewire\Server\Proxy;

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use Livewire\Component;
use Throwable;

class Status extends Component
{
    public Server $server;

    public bool $polling = false;

    public int $numberOfPolls = 0;

    protected $listeners = [
        'proxyStatusUpdated',
        'startProxyPolling',
    ];

    public function startProxyPolling()
    {
        $this->checkProxy();
    }

    public function proxyStatusUpdated()
    {
        $this->server->refresh();
    }

    public function checkProxy(bool $notification = false)
    {
        try {
            if ($this->polling) {
                if ($this->numberOfPolls >= 10) {
                    $this->polling = false;
                    $this->numberOfPolls = 0;
                    if ($notification) {
                        $this->dispatch('error', 'Proxy is not running.');
                    }

                    return null;
                }
                $this->numberOfPolls++;
            }
            $shouldStart = CheckProxy::run($this->server, true);
            if ($shouldStart) {
                StartProxy::run($this->server, false);
            }
            $this->dispatch('proxyStatusUpdated');
            if ($this->server->proxy->status === 'running') {
                $this->polling = false;
                if ($notification) {
                    $this->dispatch('success', 'Proxy is running.');
                }
            } elseif ($this->server->proxy->status === 'exited' && ! $this->server->proxy->force_stop) {
                if ($notification) {
                    $this->dispatch('error', 'Proxy has exited.');
                }
            } elseif ($this->server->proxy->force_stop) {
                if ($notification) {
                    $this->dispatch('error', 'Proxy is stopped manually.');
                }
            } elseif ($notification) {
                $this->dispatch('error', 'Proxy is not running.');
            }
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function getProxyStatus()
    {
        try {
            GetContainersStatus::run($this->server);
            // dispatch_sync(new ContainerStatusJob($this->server));
            $this->dispatch('proxyStatusUpdated');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }
}
