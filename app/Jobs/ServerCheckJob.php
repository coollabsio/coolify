<?php

namespace App\Jobs;

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\InstallLogDrain;
use App\Models\Server;
use App\Notifications\Container\ContainerRestarted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public $containers;

    public $applications;

    public $databases;

    public $services;

    public $previews;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server) {}

    public function handle()
    {
        try {
            $this->applications = $this->server->applications();
            $this->databases = $this->server->databases();
            $this->services = $this->server->services()->get();
            $this->previews = $this->server->previews();

            $up = $this->serverStatus();
            if (! $up) {
                ray('Server is not reachable.');

                return 'Server is not reachable.';
            }
            if (! $this->server->isFunctional()) {
                ray('Server is not ready.');

                return 'Server is not ready.';
            }
            if (! $this->server->isSwarmWorker() && ! $this->server->isBuildServer()) {
                ['containers' => $this->containers, 'containerReplicates' => $containerReplicates] = $this->server->getContainers();
                if (is_null($this->containers)) {
                    return 'No containers found.';
                }
                ServerStorageCheckJob::dispatch($this->server);
                GetContainersStatus::run($this->server, $this->containers, $containerReplicates);

                if ($this->server->isLogDrainEnabled()) {
                    $this->checkLogDrainContainer();
                }
                if ($this->server->proxySet() && ! $this->server->proxy->force_stop) {
                    $this->server->proxyType();
                    $foundProxyContainer = $this->containers->filter(function ($value, $key) {
                        if ($this->server->isSwarm()) {
                            return data_get($value, 'Spec.Name') === 'coolify-proxy_traefik';
                        } else {
                            return data_get($value, 'Name') === '/coolify-proxy';
                        }
                    })->first();
                    if (! $foundProxyContainer) {
                        try {
                            $shouldStart = CheckProxy::run($this->server);
                            if ($shouldStart) {
                                StartProxy::run($this->server, false);
                                $this->server->team?->notify(new ContainerRestarted('coolify-proxy', $this->server));
                            }
                        } catch (\Throwable $e) {
                            ray($e);
                        }
                    } else {
                        $this->server->proxy->status = data_get($foundProxyContainer, 'State.Status');
                        $this->server->save();
                        $connectProxyToDockerNetworks = connectProxyToNetworks($this->server);
                        instant_remote_process($connectProxyToDockerNetworks, $this->server, false);
                    }
                }
            }

        } catch (\Throwable $e) {
            ray($e->getMessage());

            return handleError($e);
        }

    }

    private function serverStatus()
    {
        ['uptime' => $uptime] = $this->server->validateConnection(false);
        if ($uptime) {
            if ($this->server->unreachable_notification_sent === true) {
                $this->server->update(['unreachable_notification_sent' => false]);
            }
        } else {
            // $this->server->team?->notify(new Unreachable($this->server));
            foreach ($this->applications as $application) {
                $application->update(['status' => 'exited']);
            }
            foreach ($this->databases as $database) {
                $database->update(['status' => 'exited']);
            }
            foreach ($this->services as $service) {
                $apps = $service->applications()->get();
                $dbs = $service->databases()->get();
                foreach ($apps as $app) {
                    $app->update(['status' => 'exited']);
                }
                foreach ($dbs as $db) {
                    $db->update(['status' => 'exited']);
                }
            }

            return false;
        }

        return true;

    }

    private function checkLogDrainContainer()
    {
        $foundLogDrainContainer = $this->containers->filter(function ($value, $key) {
            return data_get($value, 'Name') === '/coolify-log-drain';
        })->first();
        if ($foundLogDrainContainer) {
            $status = data_get($foundLogDrainContainer, 'State.Status');
            if ($status !== 'running') {
                InstallLogDrain::dispatch($this->server);
            }
        } else {
            InstallLogDrain::dispatch($this->server);
        }
    }
}
