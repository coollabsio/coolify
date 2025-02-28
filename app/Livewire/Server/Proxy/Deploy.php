<?php

namespace App\Livewire\Server\Proxy;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Events\ProxyStatusChanged;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Component;

class Deploy extends Component
{
    public Server $server;

    public bool $traefikDashboardAvailable = false;

    public ?string $currentRoute = null;

    public ?string $serverIp = null;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ProxyStatusChanged" => 'proxyStarted',
            'proxyStatusUpdated',
            'traefikDashboardAvailable',
            'serverRefresh' => 'proxyStatusUpdated',
            'checkProxy',
            'startProxy',
            'proxyChanged' => 'proxyStatusUpdated',
        ];
    }

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

    public function proxyStarted()
    {
        CheckProxy::run($this->server, true);
        $this->dispatch('proxyStatusUpdated');
    }

    public function proxyStatusUpdated()
    {
        $this->server->refresh();
    }

    public function restart()
    {
        try {
            $this->stop();
            $this->dispatch('checkProxy');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkProxy()
    {
        try {
            CheckProxy::run($this->server, true);
            $this->dispatch('startProxyPolling');
            $this->dispatch('proxyChecked');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function startProxy()
    {
        try {
            $this->server->proxy->force_stop = false;
            $this->server->save();
            $activity = StartProxy::run($this->server, force: true);
            $this->dispatch('activityMonitor', $activity->id, ProxyStatusChanged::class);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stop(bool $forceStop = true)
    {
        try {
            $containerName = $this->server->isSwarm() ? 'coolify-proxy_traefik' : 'coolify-proxy';
            $timeout = 30;

            $process = $this->stopContainer($containerName, $timeout);

            $startTime = Carbon::now()->getTimestamp();
            while ($process->running()) {
                if (Carbon::now()->getTimestamp() - $startTime >= $timeout) {
                    $this->forceStopContainer($containerName);
                    break;
                }
                usleep(100000);
            }

            $this->removeContainer($containerName);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->server->proxy->force_stop = $forceStop;
            $this->server->proxy->status = 'exited';
            $this->server->save();
            $this->dispatch('proxyStatusUpdated');
        }
    }

    private function stopContainer(string $containerName, int $timeout): InvokedProcess
    {
        return Process::timeout($timeout)->start("docker stop --time=$timeout $containerName");
    }

    private function forceStopContainer(string $containerName)
    {
        instant_remote_process(["docker kill $containerName"], $this->server, throwError: false);
    }

    private function removeContainer(string $containerName)
    {
        instant_remote_process(["docker rm -f $containerName"], $this->server, throwError: false);
    }
}
