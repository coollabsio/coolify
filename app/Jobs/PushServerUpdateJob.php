<?php

namespace App\Jobs;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\StartLogDrain;
use App\Actions\Shared\ComplexStatusCheck;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Notifications\Container\ContainerRestarted;
use App\Services\ContainerStatusAggregator;
use App\Traits\CalculatesExcludedStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\Silenced;

class PushServerUpdateJob implements ShouldBeEncrypted, ShouldQueue, Silenced
{
    use CalculatesExcludedStatus;
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

    public Collection $applicationContainerStatuses;

    public Collection $serviceContainerStatuses;

    public bool $foundProxy = false;

    public bool $foundLogDrainContainer = false;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('push-server-update-'.$this->server->uuid))->expireAfter(30)->dontRelease()];
    }

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
        $this->applicationContainerStatuses = collect();
        $this->serviceContainerStatuses = collect();
        $this->allApplicationIds = collect();
        $this->allDatabaseUuids = collect();
        $this->allTcpProxyUuids = collect();
        $this->allServiceApplicationIds = collect();
        $this->allServiceDatabaseIds = collect();
    }

    public function handle()
    {
        // Defensive initialization for Collection properties to handle queue deserialization edge cases
        $this->serviceContainerStatuses ??= collect();
        $this->applicationContainerStatuses ??= collect();
        $this->foundApplicationIds ??= collect();
        $this->foundDatabaseUuids ??= collect();
        $this->foundServiceApplicationIds ??= collect();
        $this->foundApplicationPreviewsIds ??= collect();
        $this->foundServiceDatabaseIds ??= collect();
        $this->allApplicationIds ??= collect();
        $this->allDatabaseUuids ??= collect();
        $this->allTcpProxyUuids ??= collect();
        $this->allServiceApplicationIds ??= collect();
        $this->allServiceDatabaseIds ??= collect();

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
        $this->allApplicationPreviewsIds = $this->previews->map(function ($preview) {
            return $preview->application_id.':'.$preview->pull_request_id;
        });
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
            $rawHealthStatus = data_get($container, 'health_status');
            $containerHealth = $rawHealthStatus ?? 'unknown';
            // Only append health status if container is not exited
            if ($containerStatus !== 'exited') {
                $containerStatus = "$containerStatus:$containerHealth";
            }
            $labels = collect(data_get($container, 'labels'));
            $coolify_managed = $labels->has('coolify.managed');

            if (! $coolify_managed) {
                continue;
            }

            $name = data_get($container, 'name');
            if ($name === 'coolify-log-drain' && $this->isRunning($containerStatus)) {
                $this->foundLogDrainContainer = true;
            }
            if ($labels->has('coolify.applicationId')) {
                $applicationId = $labels->get('coolify.applicationId');
                $pullRequestId = $labels->get('coolify.pullRequestId', '0');
                try {
                    if ($pullRequestId === '0') {
                        if ($this->allApplicationIds->contains($applicationId)) {
                            $this->foundApplicationIds->push($applicationId);
                        }
                        // Store container status for aggregation
                        if (! $this->applicationContainerStatuses->has($applicationId)) {
                            $this->applicationContainerStatuses->put($applicationId, collect());
                        }
                        $containerName = $labels->get('com.docker.compose.service');
                        if ($containerName) {
                            $this->applicationContainerStatuses->get($applicationId)->put($containerName, $containerStatus);
                        }
                    } else {
                        $previewKey = $applicationId.':'.$pullRequestId;
                        if ($this->allApplicationPreviewsIds->contains($previewKey)) {
                            $this->foundApplicationPreviewsIds->push($previewKey);
                        }
                        $this->updateApplicationPreviewStatus($applicationId, $pullRequestId, $containerStatus);
                    }
                } catch (\Exception $e) {
                }
            } elseif ($labels->has('coolify.serviceId')) {
                $serviceId = $labels->get('coolify.serviceId');
                $subType = $labels->get('coolify.service.subType');
                $subId = $labels->get('coolify.service.subId');
                if ($subType === 'application') {
                    $this->foundServiceApplicationIds->push($subId);
                    // Store container status for aggregation
                    $key = $serviceId.':'.$subType.':'.$subId;
                    if (! $this->serviceContainerStatuses->has($key)) {
                        $this->serviceContainerStatuses->put($key, collect());
                    }
                    $containerName = $labels->get('com.docker.compose.service');
                    if ($containerName) {
                        $this->serviceContainerStatuses->get($key)->put($containerName, $containerStatus);
                    }
                } elseif ($subType === 'database') {
                    $this->foundServiceDatabaseIds->push($subId);
                    // Store container status for aggregation
                    $key = $serviceId.':'.$subType.':'.$subId;
                    if (! $this->serviceContainerStatuses->has($key)) {
                        $this->serviceContainerStatuses->put($key, collect());
                    }
                    $containerName = $labels->get('com.docker.compose.service');
                    if ($containerName) {
                        $this->serviceContainerStatuses->get($key)->put($containerName, $containerStatus);
                    }
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

        $this->updateProxyStatus();

        $this->updateNotFoundApplicationStatus();
        $this->updateNotFoundApplicationPreviewStatus();
        $this->updateNotFoundDatabaseStatus();
        $this->updateNotFoundServiceStatus();

        $this->updateAdditionalServersStatus();

        // Aggregate multi-container application statuses
        $this->aggregateMultiContainerStatuses();

        // Aggregate multi-container service statuses
        $this->aggregateServiceContainerStatuses();

        $this->checkLogDrainContainer();
    }

    private function aggregateMultiContainerStatuses()
    {
        if ($this->applicationContainerStatuses->isEmpty()) {
            return;
        }

        foreach ($this->applicationContainerStatuses as $applicationId => $containerStatuses) {
            $application = $this->applications->where('id', $applicationId)->first();
            if (! $application) {
                continue;
            }

            // Parse docker compose to check for excluded containers
            $dockerComposeRaw = data_get($application, 'docker_compose_raw');
            $excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);

            // Filter out excluded containers
            $relevantStatuses = $containerStatuses->filter(function ($status, $containerName) use ($excludedContainers) {
                return ! $excludedContainers->contains($containerName);
            });

            // If all containers are excluded, calculate status from excluded containers
            if ($relevantStatuses->isEmpty()) {
                $aggregatedStatus = $this->calculateExcludedStatusFromStrings($containerStatuses);

                if ($aggregatedStatus && $application->status !== $aggregatedStatus) {
                    $application->status = $aggregatedStatus;
                    $application->save();
                }

                continue;
            }

            // Use ContainerStatusAggregator service for state machine logic
            $aggregator = new ContainerStatusAggregator;
            $aggregatedStatus = $aggregator->aggregateFromStrings($relevantStatuses, 0);

            // Update application status with aggregated result
            if ($aggregatedStatus && $application->status !== $aggregatedStatus) {
                $application->status = $aggregatedStatus;
                $application->save();
            }
        }
    }

    private function aggregateServiceContainerStatuses()
    {
        if ($this->serviceContainerStatuses->isEmpty()) {
            return;
        }

        foreach ($this->serviceContainerStatuses as $key => $containerStatuses) {
            // Parse key: serviceId:subType:subId
            [$serviceId, $subType, $subId] = explode(':', $key);

            $service = $this->services->where('id', $serviceId)->first();
            if (! $service) {
                continue;
            }

            // Get the service sub-resource (ServiceApplication or ServiceDatabase)
            $subResource = null;
            if ($subType === 'application') {
                $subResource = $service->applications()->where('id', $subId)->first();
            } elseif ($subType === 'database') {
                $subResource = $service->databases()->where('id', $subId)->first();
            }

            if (! $subResource) {
                continue;
            }

            // Parse docker compose from service to check for excluded containers
            $dockerComposeRaw = data_get($service, 'docker_compose_raw');
            $excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);

            // Filter out excluded containers
            $relevantStatuses = $containerStatuses->filter(function ($status, $containerName) use ($excludedContainers) {
                return ! $excludedContainers->contains($containerName);
            });

            // If all containers are excluded, calculate status from excluded containers
            if ($relevantStatuses->isEmpty()) {
                $aggregatedStatus = $this->calculateExcludedStatusFromStrings($containerStatuses);
                if ($aggregatedStatus && $subResource->status !== $aggregatedStatus) {
                    $subResource->status = $aggregatedStatus;
                    $subResource->save();
                }

                continue;
            }

            // Use ContainerStatusAggregator service for state machine logic
            // NOTE: Sentinel does NOT provide restart count data, so maxRestartCount is always 0
            $aggregator = new ContainerStatusAggregator;
            $aggregatedStatus = $aggregator->aggregateFromStrings($relevantStatuses, 0);

            // Update service sub-resource status with aggregated result
            if ($aggregatedStatus && $subResource->status !== $aggregatedStatus) {
                $subResource->status = $aggregatedStatus;
                $subResource->save();
            }
        }
    }

    private function updateApplicationStatus(string $applicationId, string $containerStatus)
    {
        $application = $this->applications->where('id', $applicationId)->first();
        if (! $application) {
            return;
        }
        if ($application->status !== $containerStatus) {
            $application->status = $containerStatus;
            $application->save();
        }
    }

    private function updateApplicationPreviewStatus(string $applicationId, string $pullRequestId, string $containerStatus)
    {
        $application = $this->previews->where('application_id', $applicationId)
            ->where('pull_request_id', $pullRequestId)
            ->first();
        if (! $application) {
            return;
        }
        if ($application->status !== $containerStatus) {
            $application->status = $containerStatus;
            $application->save();
        }
    }

    private function updateNotFoundApplicationStatus()
    {
        $notFoundApplicationIds = $this->allApplicationIds->diff($this->foundApplicationIds);
        if ($notFoundApplicationIds->isNotEmpty()) {
            $notFoundApplicationIds->each(function ($applicationId) {
                $application = Application::find($applicationId);
                if ($application) {
                    // Don't mark as exited if already exited
                    if (str($application->status)->startsWith('exited')) {
                        return;
                    }

                    // Only protection: Verify we received any container data at all
                    // If containers collection is completely empty, Sentinel might have failed
                    if ($this->containers->isEmpty()) {
                        return;
                    }

                    if ($application->status !== 'exited') {
                        $application->status = 'exited';
                        $application->save();
                    }
                }
            });
        }
    }

    private function updateNotFoundApplicationPreviewStatus()
    {
        $notFoundApplicationPreviewsIds = $this->allApplicationPreviewsIds->diff($this->foundApplicationPreviewsIds);
        if ($notFoundApplicationPreviewsIds->isNotEmpty()) {
            $notFoundApplicationPreviewsIds->each(function ($previewKey) {
                // Parse the previewKey format "application_id:pull_request_id"
                $parts = explode(':', $previewKey);
                if (count($parts) !== 2) {
                    return;
                }

                $applicationId = $parts[0];
                $pullRequestId = $parts[1];

                $applicationPreview = $this->previews->where('application_id', $applicationId)
                    ->where('pull_request_id', $pullRequestId)
                    ->first();

                if ($applicationPreview) {
                    // Don't mark as exited if already exited
                    if (str($applicationPreview->status)->startsWith('exited')) {
                        return;
                    }

                    // Only protection: Verify we received any container data at all
                    // If containers collection is completely empty, Sentinel might have failed
                    if ($this->containers->isEmpty()) {

                        return;
                    }
                    if ($applicationPreview->status !== 'exited') {
                        $applicationPreview->status = 'exited';
                        $applicationPreview->save();
                    }
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
                        StartProxy::run($this->server, async: false);
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
        if ($database->status !== $containerStatus) {
            $database->status = $containerStatus;
            $database->save();
        }
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
                    if ($database->status !== 'exited') {
                        $database->status = 'exited';
                        $database->save();
                    }
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
            if ($application) {
                if ($application->status !== $containerStatus) {
                    $application->status = $containerStatus;
                    $application->save();
                }
            }
        } elseif ($subType === 'database') {
            $database = $service->databases()->where('id', $subId)->first();
            if ($database) {
                if ($database->status !== $containerStatus) {
                    $database->status = $containerStatus;
                    $database->save();
                }
            }
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
                    if ($application->status !== 'exited') {
                        $application->status = 'exited';
                        $application->save();
                    }
                }
            });
        }
        if ($notFoundServiceDatabaseIds->isNotEmpty()) {
            $notFoundServiceDatabaseIds->each(function ($serviceDatabaseId) {
                $database = ServiceDatabase::find($serviceDatabaseId);
                if ($database) {
                    if ($database->status !== 'exited') {
                        $database->status = 'exited';
                        $database->save();
                    }
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
