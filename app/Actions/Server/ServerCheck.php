<?php

namespace App\Actions\Server;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerStorageCheckJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Notifications\Container\ContainerRestarted;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsAction;

class ServerCheck
{
    use AsAction;

    public Server $server;

    public bool $isSentinel = false;

    public $containers;

    public $databases;

    public function handle(Server $server, $data = null)
    {
        $this->server = $server;
        try {
            if ($this->server->isFunctional() === false) {
                return 'Server is not functional.';
            }

            if (! $this->server->isSwarmWorker() && ! $this->server->isBuildServer()) {

                if (isset($data)) {
                    $data = collect($data);

                    $this->server->sentinelHeartbeat();

                    $this->containers = collect(data_get($data, 'containers'));

                    $filesystemUsageRoot = data_get($data, 'filesystem_usage_root.used_percentage');
                    ServerStorageCheckJob::dispatch($this->server, $filesystemUsageRoot);

                    $containerReplicates = null;
                    $this->isSentinel = true;
                } else {
                    ['containers' => $this->containers, 'containerReplicates' => $containerReplicates] = $this->server->getContainers();
                    // ServerStorageCheckJob::dispatch($this->server);
                }

                if (is_null($this->containers)) {
                    return 'No containers found.';
                }

                if (isset($containerReplicates)) {
                    foreach ($containerReplicates as $containerReplica) {
                        $name = data_get($containerReplica, 'Name');
                        $this->containers = $this->containers->map(function ($container) use ($name, $containerReplica) {
                            if (data_get($container, 'Spec.Name') === $name) {
                                $replicas = data_get($containerReplica, 'Replicas');
                                $running = str($replicas)->explode('/')[0];
                                $total = str($replicas)->explode('/')[1];
                                if ($running === $total) {
                                    data_set($container, 'State.Status', 'running');
                                    data_set($container, 'State.Health.Status', 'healthy');
                                } else {
                                    data_set($container, 'State.Status', 'starting');
                                    data_set($container, 'State.Health.Status', 'unhealthy');
                                }
                            }

                            return $container;
                        });
                    }
                }
                $this->checkContainers();

                if ($this->server->isSentinelEnabled() && $this->isSentinel === false) {
                    CheckAndStartSentinelJob::dispatch($this->server);
                }

                if ($this->server->isLogDrainEnabled()) {
                    $this->checkLogDrainContainer();
                }

                if ($this->server->proxySet() && ! $this->server->proxy->force_stop) {
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
            return handleError($e);
        }
    }

    private function checkLogDrainContainer()
    {
        $foundLogDrainContainer = $this->containers->filter(function ($value, $key) {
            return data_get($value, 'Name') === '/coolify-log-drain';
        })->first();
        if ($foundLogDrainContainer) {
            $status = data_get($foundLogDrainContainer, 'State.Status');
            if ($status !== 'running') {
                StartLogDrain::dispatch($this->server);
            }
        } else {
            StartLogDrain::dispatch($this->server);
        }
    }

    private function checkContainers()
    {
        foreach ($this->containers as $container) {
            if ($this->isSentinel) {
                $labels = Arr::undot(data_get($container, 'labels'));
            } else {
                if ($this->server->isSwarm()) {
                    $labels = Arr::undot(data_get($container, 'Spec.Labels'));
                } else {
                    $labels = Arr::undot(data_get($container, 'Config.Labels'));
                }
            }
            $managed = data_get($labels, 'coolify.managed');
            if (! $managed) {
                continue;
            }
            $uuid = data_get($labels, 'coolify.name');
            if (! $uuid) {
                $uuid = data_get($labels, 'com.docker.compose.service');
            }

            if ($this->isSentinel) {
                $containerStatus = data_get($container, 'state');
                $containerHealth = data_get($container, 'health_status');
            } else {
                $containerStatus = data_get($container, 'State.Status');
                $containerHealth = data_get($container, 'State.Health.Status', 'unhealthy');
            }
            $containerStatus = "$containerStatus ($containerHealth)";

            $applicationId = data_get($labels, 'coolify.applicationId');
            $serviceId = data_get($labels, 'coolify.serviceId');
            $databaseId = data_get($labels, 'coolify.databaseId');
            $pullRequestId = data_get($labels, 'coolify.pullRequestId');

            if ($applicationId) {
                // Application
                if ($pullRequestId != 0) {
                    if (str($applicationId)->contains('-')) {
                        $applicationId = str($applicationId)->before('-');
                    }
                    $preview = ApplicationPreview::where('application_id', $applicationId)->where('pull_request_id', $pullRequestId)->first();
                    if ($preview) {
                        $preview->update(['status' => $containerStatus]);
                    }
                } else {
                    $application = Application::where('id', $applicationId)->first();
                    if ($application) {
                        $application->update([
                            'status' => $containerStatus,
                            'last_online_at' => now(),
                        ]);
                    }
                }
            } elseif (isset($serviceId)) {
                // Service
                $subType = data_get($labels, 'coolify.service.subType');
                $subId = data_get($labels, 'coolify.service.subId');
                $service = Service::where('id', $serviceId)->first();
                if (! $service) {
                    continue;
                }
                if ($subType === 'application') {
                    $service = ServiceApplication::where('id', $subId)->first();
                } else {
                    $service = ServiceDatabase::where('id', $subId)->first();
                }
                if ($service) {
                    $service->update([
                        'status' => $containerStatus,
                        'last_online_at' => now(),
                    ]);
                    if ($subType === 'database') {
                        $isPublic = data_get($service, 'is_public');
                        if ($isPublic) {
                            $foundTcpProxy = $this->containers->filter(function ($value, $key) use ($uuid) {
                                if ($this->isSentinel) {
                                    return data_get($value, 'name') === $uuid.'-proxy';
                                } else {

                                    if ($this->server->isSwarm()) {
                                        return data_get($value, 'Spec.Name') === "coolify-proxy_$uuid";
                                    } else {
                                        return data_get($value, 'Name') === "/$uuid-proxy";
                                    }
                                }
                            })->first();
                            if (! $foundTcpProxy) {
                                StartDatabaseProxy::run($service);
                            }
                        }
                    }
                }
            } else {
                // Database
                if (is_null($this->databases)) {
                    $this->databases = $this->server->databases();
                }
                $database = $this->databases->where('uuid', $uuid)->first();
                if ($database) {
                    $database->update([
                        'status' => $containerStatus,
                        'last_online_at' => now(),
                    ]);

                    $isPublic = data_get($database, 'is_public');
                    if ($isPublic) {
                        $foundTcpProxy = $this->containers->filter(function ($value, $key) use ($uuid) {
                            if ($this->isSentinel) {
                                return data_get($value, 'name') === $uuid.'-proxy';
                            } else {
                                if ($this->server->isSwarm()) {
                                    return data_get($value, 'Spec.Name') === "coolify-proxy_$uuid";
                                } else {

                                    return data_get($value, 'Name') === "/$uuid-proxy";
                                }
                            }
                        })->first();
                        if (! $foundTcpProxy) {
                            StartDatabaseProxy::run($database);
                            // $this->server->team?->notify(new ContainerRestarted("TCP Proxy for database", $this->server));
                        }
                    }
                }
            }
        }
    }
}
