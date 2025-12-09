<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Enums\ProxyTypes;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Navbar extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $isChecking = false;

    public ?string $currentRoute = null;

    public bool $traefikDashboardAvailable = false;

    public ?string $serverIp = null;

    public ?string $proxyStatus = 'unknown';

    public ?string $lastNotifiedStatus = null;

    public bool $restartInitiated = false;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            'refreshServerShow' => 'refreshServer',
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
            $this->authorize('manageProxy', $this->server);

            // Prevent duplicate restart calls
            if ($this->restartInitiated) {
                return;
            }
            $this->restartInitiated = true;

            // Always use background job for all servers
            RestartProxyJob::dispatch($this->server);

        } catch (\Throwable $e) {
            $this->restartInitiated = false;

            return handleError($e, $this);
        }
    }

    public function checkProxy()
    {
        try {
            $this->authorize('manageProxy', $this->server);
            CheckProxy::run($this->server, true);
            $this->dispatch('startProxy')->self();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function startProxy()
    {
        try {
            $this->authorize('manageProxy', $this->server);
            $activity = StartProxy::run($this->server, force: true);
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stop(bool $forceStop = true)
    {
        try {
            $this->authorize('manageProxy', $this->server);
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

    public function showNotification($event = null)
    {
        $previousStatus = $this->proxyStatus;
        $this->server->refresh();
        $this->proxyStatus = $this->server->proxy->status ?? 'unknown';

        // If event contains activityId, open activity monitor
        if ($event && isset($event['activityId'])) {
            $this->dispatch('activityMonitor', $event['activityId']);
        }

        // Reset restart flag when proxy reaches a stable state
        if (in_array($this->proxyStatus, ['running', 'exited', 'error'])) {
            $this->restartInitiated = false;
        }

        // Skip notification if we already notified about this status (prevents duplicates)
        if ($this->lastNotifiedStatus === $this->proxyStatus) {
            return;
        }

        switch ($this->proxyStatus) {
            case 'running':
                $this->loadProxyConfiguration();
                // Only show "Proxy is running" notification when transitioning from a stopped/error state
                // Don't show during normal start/restart flows (starting, restarting, stopping)
                if (in_array($previousStatus, ['exited', 'stopped', 'unknown', null])) {
                    $this->dispatch('success', 'Proxy is running.');
                    $this->lastNotifiedStatus = $this->proxyStatus;
                }
                break;
            case 'exited':
                // Only show "Proxy has exited" notification when transitioning from running state
                // Don't show during normal stop/restart flows (stopping, restarting)
                if (in_array($previousStatus, ['running'])) {
                    $this->dispatch('info', 'Proxy has exited.');
                    $this->lastNotifiedStatus = $this->proxyStatus;
                }
                break;
            case 'stopping':
                // $this->dispatch('info', 'Proxy is stopping.');
                $this->lastNotifiedStatus = $this->proxyStatus;
                break;
            case 'starting':
                // $this->dispatch('info', 'Proxy is starting.');
                $this->lastNotifiedStatus = $this->proxyStatus;
                break;
            case 'restarting':
                // $this->dispatch('info', 'Proxy is restarting.');
                $this->lastNotifiedStatus = $this->proxyStatus;
                break;
            case 'error':
                $this->dispatch('error', 'Proxy restart failed. Check logs.');
                $this->lastNotifiedStatus = $this->proxyStatus;
                break;
            case 'unknown':
                // Don't notify for unknown status - too noisy
                break;
            default:
                // Don't notify for other statuses
                break;
        }

    }

    public function refreshServer()
    {
        $this->server->refresh();
        $this->server->load('settings');
    }

    /**
     * Check if Traefik has any outdated version info (patch or minor upgrade).
     * This shows a warning indicator in the navbar.
     */
    public function getHasTraefikOutdatedProperty(): bool
    {
        if ($this->server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return false;
        }

        // Check if server has outdated info stored
        $outdatedInfo = $this->server->traefik_outdated_info;

        return ! empty($outdatedInfo) && isset($outdatedInfo['type']);
    }

    public function render()
    {
        return view('livewire.server.navbar');
    }
}
