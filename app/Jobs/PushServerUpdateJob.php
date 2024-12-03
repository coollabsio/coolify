<?php

namespace App\Jobs;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\StartLogDrain;
use App\Actions\Shared\ComplexStatusCheck;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Notifications\Container\ContainerRestarted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PushServerUpdateJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 30;

    public Collection $containers;

    public Collection $applications;

    public Collection $previews;

    public Collection $databases;

    public Collection $services;

    public Collection $allApplicationIds;

    public Collection $allDatabaseUuids;

    public Collection $allTcpProxyUuids;

    public Collection $allServiceApplicationIds;

    public Collection $allApplicationPreviewsIds;

    public Collection $allServiceDatabaseIds;

    public Collection $allApplicationsWithAdditionalServers;

    public Collection $foundApplicationIds;

    public Collection $foundDatabaseUuids;

    public Collection $foundServiceApplicationIds;

    public Collection $foundServiceDatabaseIds;

    public Collection $foundApplicationPreviewsIds;

    public bool $foundProxy = false;

    public bool $foundLogDrainContainer = false;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server, public $data)
    {
        $this->containers = collect();
        $this->foundApplicationIds = collect();
        $this->foundDatabaseUuids = collect();
        $this->foundServiceApplicationIds = collect();
        $this->foundApplicationPreviewsIds = collect();
        $this->foundServiceDatabaseIds = collect();
        $this->allApplicationIds = collect();
        $this->allDatabaseUuids = collect();
        $this->allTcpProxyUuids = collect();
        $this->allServiceApplicationIds = collect();
        $this->allServiceDatabaseIds = collect();
    }

    public function handle()
    {
        // TODO: Swarm is not supported yet
        if (! $this->data) {
            throw new \Exception('No data provided');
        }
        $data = collect($this->data);

        $this->server->sentinelHeartbeat();

        $this->containers = collect(data_get($data, 'containers'));

        $filesystemUsageRoot = data_get($data, 'filesystem_usage_root.used_percentage');
        ServerStorageCheckJob::dispatch($this->server, $filesystemUsageRoot);

        if ($this->containers->isEmpty()) {
            return;
        }
        $this->applications = $this->server->applications();
        $this->databases = $this->server->databases();
        $this->previews = $this->server->previews();
        $this->services = $this->server->services()->get();
        $this->allApplicationIds = $this->applications->filter(function ($application) {
            return $application->additional_servers->count() === 0;
        })->pluck('id');
        $this->allApplicationsWithAdditionalServers = $this->applications->filter(function ($application) {
            return $application->additional_servers->count() > 0;
        });
        $this->allApplicationPreviewsIds = $this->previews->pluck('id');
        $this->allDatabaseUuids = $this->databases->pluck('uuid');
        $this->allTcpProxyUuids = $this->databases->where('is_public', true)->pluck('uuid');
        $this->services->each(function ($service) {
            $service->applications()->pluck('id')->each(function ($applicationId) {
                $this->allServiceApplicationIds->push($applicationId);
            });
            $service->databases()->pluck('id')->each(function ($databaseId) {
                $this->allServiceDatabaseIds->push($databaseId);
            });
        });

        foreach ($this->containers as $container) {
            $containerStatus = data_get($container, 'state', 'exited');
            $containerHealth = data_get($container, 'health_status', 'unhealthy');
            $containerStatus = "$containerStatus ($containerHealth)";
            $labels = collect(data_get($container, 'labels'));
            $coolify_managed = $labels->has('coolify.managed');
            if ($coolify_managed) {
                $name = data_get($container, 'name');
                if ($name === 'coolify-log-drain' && $this->isRunning($containerStatus)) {
                    $this->foundLogDrainContainer = true;
                }
                if ($labels->has('coolify.applicationId')) {
                    $applicationId = $labels->get('coolify.applicationId');
                    $pullRequestId = data_get($labels, 'coolify.pullRequestId', '0');
                    try {
                        if ($pullRequestId === '0') {
                            if ($this->allApplicationIds->contains($applicationId) && $this->isRunning($containerStatus)) {
                                $this->foundApplicationIds->push($applicationId);
                            }
                            $this->updateApplicationStatus($applicationId, $containerStatus);
                        } else {
                            if ($this->allApplicationPreviewsIds->contains($applicationId) && $this->isRunning($containerStatus)) {
                                $this->foundApplicationPreviewsIds->push($applicationId);
                            }
                            $this->updateApplicationPreviewStatus($applicationId, $containerStatus);
                        }
                    } catch (\Exception $e) {
                    }
                } elseif ($labels->has('coolify.serviceId')) {
                    $serviceId = $labels->get('coolify.serviceId');
                    $subType = $labels->get('coolify.service.subType');
                    $subId = $labels->get('coolify.service.subId');
                    if ($subType === 'application' && $this->isRunning($containerStatus)) {
                        $this->foundServiceApplicationIds->push($subId);
                        $this->updateServiceSubStatus($serviceId, $subType, $subId, $containerStatus);
                    } elseif ($subType === 'database' && $this->isRunning($containerStatus)) {
                        $this->foundServiceDatabaseIds->push($subId);
                        $this->updateServiceSubStatus($serviceId, $subType, $subId, $containerStatus);
                    }
                } else {
                    $uuid = $labels->get('com.docker.compose.service');
                    $type = $labels->get('coolify.type');
                    if ($name === 'coolify-proxy' && $this->isRunning($containerStatus)) {
                        $this->foundProxy = true;
                    } elseif ($type === 'service' && $this->isRunning($containerStatus)) {
                    } else {
                        if ($this->allDatabaseUuids->contains($uuid) && $this->isRunning($containerStatus)) {
                            $this->foundDatabaseUuids->push($uuid);
                            if ($this->allTcpProxyUuids->contains($uuid) && $this->isRunning($containerStatus)) {
                                $this->updateDatabaseStatus($uuid, $containerStatus, tcpProxy: true);
                            } else {
                                $this->updateDatabaseStatus($uuid, $containerStatus, tcpProxy: false);
                            }
                        }
                    }
                }
            }
        }

        $this->updateProxyStatus();

        $this->updateNotFoundApplicationStatus();
        $this->updateNotFoundApplicationPreviewStatus();
        $this->updateNotFoundDatabaseStatus();
        $this->updateNotFoundServiceStatus();

        $this->updateAdditionalServersStatus();

        $this->checkLogDrainContainer();
    }

    private function updateApplicationStatus(string $applicationId, string $containerStatus)
    {
        $application = $this->applications->where('id', $applicationId)->first();
        if (! $application) {
            return;
        }
        $application->status = $containerStatus;
        $application->save();
    }

    private function updateApplicationPreviewStatus(string $applicationId, string $containerStatus)
    {
        $application = $this->previews->where('id', $applicationId)->first();
        if (! $application) {
            return;
        }
        $application->status = $containerStatus;
        $application->save();
    }

    private function updateNotFoundApplicationStatus()
    {
        $notFoundApplicationIds = $this->allApplicationIds->diff($this->foundApplicationIds);
        if ($notFoundApplicationIds->isNotEmpty()) {
            $notFoundApplicationIds->each(function ($applicationId) {
                $application = Application::find($applicationId);
                if ($application) {
                    $application->status = 'exited';
                    $application->save();
                }
            });
        }
    }

    private function updateNotFoundApplicationPreviewStatus()
    {
        $notFoundApplicationPreviewsIds = $this->allApplicationPreviewsIds->diff($this->foundApplicationPreviewsIds);
        if ($notFoundApplicationPreviewsIds->isNotEmpty()) {
            $notFoundApplicationPreviewsIds->each(function ($applicationPreviewId) {
                $applicationPreview = ApplicationPreview::find($applicationPreviewId);
                if ($applicationPreview) {
                    $applicationPreview->status = 'exited';
                    $applicationPreview->save();
                }
            });
        }
    }

    private function updateProxyStatus()
    {
        // If proxy is not found, start it
        if ($this->server->isProxyShouldRun()) {
            if ($this->foundProxy === false) {
                try {
                    if (CheckProxy::run($this->server)) {
                        StartProxy::run($this->server, false);
                        $this->server->team?->notify(new ContainerRestarted('coolify-proxy', $this->server));
                    }
                } catch (\Throwable $e) {
                }
            } else {
                $connectProxyToDockerNetworks = connectProxyToNetworks($this->server);
                instant_remote_process($connectProxyToDockerNetworks, $this->server, false);
            }
        }
    }

    private function updateDatabaseStatus(string $databaseUuid, string $containerStatus, bool $tcpProxy = false)
    {
        $database = $this->databases->where('uuid', $databaseUuid)->first();
        if (! $database) {
            return;
        }
        $database->status = $containerStatus;
        $database->save();
        if ($this->isRunning($containerStatus) && $tcpProxy) {
            $tcpProxyContainerFound = $this->containers->filter(function ($value, $key) use ($databaseUuid) {
                return data_get($value, 'name') === "$databaseUuid-proxy" && data_get($value, 'state') === 'running';
            })->first();
            if (! $tcpProxyContainerFound) {
                StartDatabaseProxy::dispatch($database);
                $this->server->team?->notify(new ContainerRestarted("TCP Proxy for {$database->name}", $this->server));
            } else {
            }
        }
    }

    private function updateNotFoundDatabaseStatus()
    {
        $notFoundDatabaseUuids = $this->allDatabaseUuids->diff($this->foundDatabaseUuids);
        if ($notFoundDatabaseUuids->isNotEmpty()) {
            $notFoundDatabaseUuids->each(function ($databaseUuid) {
                $database = $this->databases->where('uuid', $databaseUuid)->first();
                if ($database) {
                    $database->status = 'exited';
                    $database->save();
                    if ($database->is_public) {
                        StopDatabaseProxy::dispatch($database);
                    }
                }
            });
        }
    }

    private function updateServiceSubStatus(string $serviceId, string $subType, string $subId, string $containerStatus)
    {
        $service = $this->services->where('id', $serviceId)->first();
        if (! $service) {
            return;
        }
        if ($subType === 'application') {
            $application = $service->applications()->where('id', $subId)->first();
            $application->status = $containerStatus;
            $application->save();
        } elseif ($subType === 'database') {
            $database = $service->databases()->where('id', $subId)->first();
            $database->status = $containerStatus;
            $database->save();
        } else {
        }
    }

    private function updateNotFoundServiceStatus()
    {
        $notFoundServiceApplicationIds = $this->allServiceApplicationIds->diff($this->foundServiceApplicationIds);
        $notFoundServiceDatabaseIds = $this->allServiceDatabaseIds->diff($this->foundServiceDatabaseIds);
        if ($notFoundServiceApplicationIds->isNotEmpty()) {
            $notFoundServiceApplicationIds->each(function ($serviceApplicationId) {
                $application = ServiceApplication::find($serviceApplicationId);
                if ($application) {
                    $application->status = 'exited';
                    $application->save();
                }
            });
        }
        if ($notFoundServiceDatabaseIds->isNotEmpty()) {
            $notFoundServiceDatabaseIds->each(function ($serviceDatabaseId) {
                $database = ServiceDatabase::find($serviceDatabaseId);
                if ($database) {
                    $database->status = 'exited';
                    $database->save();
                }
            });
        }
    }

    private function updateAdditionalServersStatus()
    {
        $this->allApplicationsWithAdditionalServers->each(function ($application) {
            ComplexStatusCheck::run($application);
        });
    }

    private function isRunning(string $containerStatus)
    {
        return str($containerStatus)->contains('running');
    }

    private function checkLogDrainContainer()
    {
        if ($this->server->isLogDrainEnabled() && $this->foundLogDrainContainer === false) {
            StartLogDrain::dispatch($this->server);
        }
    }
}
