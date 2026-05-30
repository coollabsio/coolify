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
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\SwarmDocker;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public Collection $applicationsById;

    public Collection $previewsByKey;

    public Collection $databasesByUuid;

    public Collection $servicesById;

    public Collection $serviceApplicationsById;

    public Collection $serviceDatabasesById;

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

    private ?array $cachedDestinationIds = null;

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
        $this->applicationsById = collect();
        $this->previewsByKey = collect();
        $this->databasesByUuid = collect();
        $this->servicesById = collect();
        $this->serviceApplicationsById = collect();
        $this->serviceDatabasesById = collect();
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
        $this->applicationsById ??= collect();
        $this->previewsByKey ??= collect();
        $this->databasesByUuid ??= collect();
        $this->servicesById ??= collect();
        $this->serviceApplicationsById ??= collect();
        $this->serviceDatabasesById ??= collect();

        // Eager-load relations the job touches repeatedly to avoid lazy-load queries
        // (settings: disk threshold, isProxyShouldRun, isLogDrainEnabled; team: notifications).
        $this->server->loadMissing(['settings', 'team']);

        // TODO: Swarm is not supported yet
        if (! $this->data) {
            throw new \Exception('No data provided');
        }
        $data = collect($this->data);

        // Heartbeat is updated by SentinelController on every push, before dispatch.
        $this->containers = collect(data_get($data, 'containers'));
        $filesystemUsageRoot = data_get($data, 'filesystem_usage_root.used_percentage');

        // Only dispatch the storage check when disk usage is at/above the notification
        // threshold AND the value changed. Below the threshold ServerStorageCheckJob
        // has nothing to do (it only sends a HighDiskUsage notification), so dispatching
        // it is wasted work — and most servers sit well below the threshold.
        $diskThreshold = data_get($this->server, 'settings.server_disk_usage_notification_threshold', 80);
        $storageCacheKey = 'storage-check:'.$this->server->id;
        $lastPercentage = Cache::get($storageCacheKey);
        if ($filesystemUsageRoot !== null
            && $filesystemUsageRoot >= $diskThreshold
            && (string) $lastPercentage !== (string) $filesystemUsageRoot) {
            Cache::put($storageCacheKey, $filesystemUsageRoot, 600);
            ServerStorageCheckJob::dispatch($this->server, $filesystemUsageRoot);
        } elseif ($filesystemUsageRoot !== null && $filesystemUsageRoot < $diskThreshold) {
            Cache::forget($storageCacheKey);
        }

        if ($this->containers->isEmpty()) {
            return;
        }

        $this->applications = $this->loadApplications();
        $this->databases = $this->loadDatabases();
        $this->previews = $this->loadPreviews();
        $this->services = $this->loadServices();
        $this->applicationsById = $this->applications->keyBy(fn ($application) => (string) $application->id);
        $this->previewsByKey = $this->previews->keyBy(fn ($preview) => $preview->application_id.':'.$preview->pull_request_id);
        $this->databasesByUuid = $this->databases->keyBy('uuid');
        $this->servicesById = $this->services->keyBy(fn ($service) => (string) $service->id);
        $this->serviceApplicationsById = $this->services->flatMap(fn ($service) => $service->applications)->keyBy(fn ($application) => (string) $application->id);
        $this->serviceDatabasesById = $this->services->flatMap(fn ($service) => $service->databases)->keyBy(fn ($database) => (string) $database->id);

        $this->allApplicationIds = $this->applications->filter(function ($application) {
            return $application->additional_servers_count === 0;
        })->pluck('id');
        $this->allApplicationsWithAdditionalServers = $this->applications->filter(function ($application) {
            return $application->additional_servers_count > 0;
        });
        $this->allApplicationPreviewsIds = $this->previews->map(function ($preview) {
            return $preview->application_id.':'.$preview->pull_request_id;
        });
        $this->allDatabaseUuids = $this->databases->pluck('uuid');
        $this->allTcpProxyUuids = $this->databases->where('is_public', true)->pluck('uuid');
        $this->allServiceApplicationIds = $this->serviceApplicationsById->keys();
        $this->allServiceDatabaseIds = $this->serviceDatabasesById->keys();

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
                if (empty(trim((string) $subId))) {
                    continue;
                }
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
                    if ($this->allDatabaseUuids->contains($uuid) && $this->isActiveOrTransient($containerStatus)) {
                        $this->foundDatabaseUuids->push($uuid);
                        // TCP proxy should only be started/managed when database is actually running
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

    private function loadApplications(): Collection
    {
        [$standaloneDockerIds, $swarmDockerIds] = $this->serverDestinationIds();

        $applications = ($standaloneDockerIds->isNotEmpty() || $swarmDockerIds->isNotEmpty())
            ? Application::withoutGlobalScope('withRelations')
                ->select([
                    'id',
                    'uuid',
                    'name',
                    'status',
                    'build_pack',
                    'docker_compose_raw',
                    'destination_id',
                    'destination_type',
                    'last_online_at',
                ])
                ->withCount('additional_servers')
                ->where(fn ($query) => $this->scopeDestination($query, $standaloneDockerIds, $swarmDockerIds))
                ->get()
            : collect();

        $additionalApplicationIds = DB::table('additional_destinations')
            ->where('server_id', $this->server->id)
            ->pluck('application_id');

        if ($additionalApplicationIds->isNotEmpty()) {
            $applications = $applications->concat(
                Application::withoutGlobalScope('withRelations')
                    ->select([
                        'id',
                        'uuid',
                        'name',
                        'status',
                        'build_pack',
                        'docker_compose_raw',
                        'destination_id',
                        'destination_type',
                        'last_online_at',
                    ])
                    ->withCount('additional_servers')
                    ->whereIn('id', $additionalApplicationIds)
                    ->get()
            );
        }

        return $applications->unique('id')->values();
    }

    private function loadPreviews(): Collection
    {
        $applicationIds = $this->applications->pluck('id');

        if ($applicationIds->isEmpty()) {
            return collect();
        }

        return ApplicationPreview::query()
            ->select([
                'id',
                'application_id',
                'pull_request_id',
                'status',
                'last_online_at',
            ])
            ->whereIn('application_id', $applicationIds)
            ->get();
    }

    private function loadServices(): Collection
    {
        return $this->server->services()
            ->select([
                'id',
                'server_id',
                'uuid',
                'docker_compose_raw',
            ])
            ->with([
                'applications:id,service_id,status,last_online_at',
                'databases:id,service_id,status,last_online_at,is_public,name',
            ])
            ->get();
    }

    private function loadDatabases(): Collection
    {
        [$standaloneDockerIds, $swarmDockerIds] = $this->serverDestinationIds();
        if ($standaloneDockerIds->isEmpty() && $swarmDockerIds->isEmpty()) {
            return collect();
        }
        $databaseColumns = [
            'id',
            'uuid',
            'name',
            'status',
            'is_public',
            'destination_id',
            'destination_type',
            'last_online_at',
            'restart_count',
            'last_restart_at',
            'last_restart_type',
        ];

        return collect([
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMongodb::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ])->flatMap(function (string $databaseClass) use ($databaseColumns, $standaloneDockerIds, $swarmDockerIds) {
            return $databaseClass::query()
                ->select($databaseColumns)
                ->where(fn ($query) => $this->scopeDestination($query, $standaloneDockerIds, $swarmDockerIds))
                ->get();
        })->filter(fn ($database) => data_get($database, 'name') !== 'coolify-db')->values();
    }

    private function serverDestinationIds(): array
    {
        if ($this->cachedDestinationIds !== null) {
            return $this->cachedDestinationIds;
        }

        return $this->cachedDestinationIds = [
            StandaloneDocker::where('server_id', $this->server->id)->pluck('id'),
            SwarmDocker::where('server_id', $this->server->id)->pluck('id'),
        ];
    }

    private function scopeDestination($query, Collection $standaloneDockerIds, Collection $swarmDockerIds): void
    {
        $query->where(function ($query) use ($standaloneDockerIds) {
            $query->where('destination_type', StandaloneDocker::class)
                ->whereIn('destination_id', $standaloneDockerIds);
        })->orWhere(function ($query) use ($swarmDockerIds) {
            $query->where('destination_type', SwarmDocker::class)
                ->whereIn('destination_id', $swarmDockerIds);
        });
    }

    private function aggregateMultiContainerStatuses()
    {
        if ($this->applicationContainerStatuses->isEmpty()) {
            return;
        }

        foreach ($this->applicationContainerStatuses as $applicationId => $containerStatuses) {
            $application = $this->applicationsById->get((string) $applicationId);
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
            // Use preserveRestarting: true so applications show "Restarting" instead of "Degraded"
            $aggregator = new ContainerStatusAggregator;
            $aggregatedStatus = $aggregator->aggregateFromStrings($relevantStatuses, 0, preserveRestarting: true);

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

            if (empty($subId)) {
                continue;
            }

            $service = $this->servicesById->get((string) $serviceId);
            if (! $service) {
                continue;
            }

            // Get the service sub-resource (ServiceApplication or ServiceDatabase)
            $subResource = null;
            if ($subType === 'application') {
                $subResource = $this->serviceApplicationsById->get((string) $subId);
            } elseif ($subType === 'database') {
                $subResource = $this->serviceDatabasesById->get((string) $subId);
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
            // Use preserveRestarting: true so individual sub-resources show "Restarting" instead of "Degraded"
            $aggregator = new ContainerStatusAggregator;
            $aggregatedStatus = $aggregator->aggregateFromStrings($relevantStatuses, 0, preserveRestarting: true);

            // Update service sub-resource status with aggregated result
            if ($aggregatedStatus && $subResource->status !== $aggregatedStatus) {
                $subResource->status = $aggregatedStatus;
                $subResource->save();
            }
        }
    }

    private function updateApplicationStatus(string $applicationId, string $containerStatus)
    {
        $application = $this->applicationsById->get((string) $applicationId);
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
        $application = $this->previewsByKey->get($applicationId.':'.$pullRequestId);
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
        if ($notFoundApplicationIds->isEmpty()) {
            return;
        }

        // Only protection: Verify we received any container data at all
        // If containers collection is completely empty, Sentinel might have failed
        if ($this->containers->isEmpty()) {
            return;
        }

        // Batch update: mark all not-found applications as exited (excluding already exited ones)
        Application::whereIn('id', $notFoundApplicationIds)
            ->where('status', 'not like', 'exited%')
            ->update(['status' => 'exited']);
    }

    private function updateNotFoundApplicationPreviewStatus()
    {
        $notFoundApplicationPreviewsIds = $this->allApplicationPreviewsIds->diff($this->foundApplicationPreviewsIds);
        if ($notFoundApplicationPreviewsIds->isEmpty()) {
            return;
        }

        // Only protection: Verify we received any container data at all
        // If containers collection is completely empty, Sentinel might have failed
        if ($this->containers->isEmpty()) {
            return;
        }

        // Collect IDs of previews that need to be marked as exited
        $previewIdsToUpdate = collect();
        foreach ($notFoundApplicationPreviewsIds as $previewKey) {
            // Parse the previewKey format "application_id:pull_request_id"
            $parts = explode(':', $previewKey);
            if (count($parts) !== 2) {
                continue;
            }

            $applicationId = $parts[0];
            $pullRequestId = $parts[1];

            $applicationPreview = $this->previewsByKey->get($applicationId.':'.$pullRequestId);

            if ($applicationPreview && ! str($applicationPreview->status)->startsWith('exited')) {
                $previewIdsToUpdate->push($applicationPreview->id);
            }
        }

        // Batch update all collected preview IDs
        if ($previewIdsToUpdate->isNotEmpty()) {
            ApplicationPreview::whereIn('id', $previewIdsToUpdate)->update(['status' => 'exited']);
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
                // Connect proxy to networks periodically as a safety net to avoid excessive job dispatches.
                // On-demand triggers (new network, service deploy) use dispatchSync() and bypass this.
                $proxyCacheKey = 'connect-proxy:'.$this->server->id;
                if (! Cache::has($proxyCacheKey)) {
                    Cache::put($proxyCacheKey, true, config('constants.proxy.connect_networks_interval_seconds', 3600));
                    ConnectProxyToNetworksJob::dispatch($this->server);
                }
            }
        }
    }

    private function updateDatabaseStatus(string $databaseUuid, string $containerStatus, bool $tcpProxy = false)
    {
        $database = $this->databasesByUuid->get($databaseUuid);
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
            }
        } elseif ($this->isRunning($containerStatus) && ! $tcpProxy) {
            // Clean up orphaned proxy containers when is_public=false
            $orphanedProxy = $this->containers->filter(function ($value, $key) use ($databaseUuid) {
                return data_get($value, 'name') === "$databaseUuid-proxy" && data_get($value, 'state') === 'running';
            })->first();
            if ($orphanedProxy) {
                StopDatabaseProxy::dispatch($database);
            }
        }
    }

    private function updateNotFoundDatabaseStatus()
    {
        $notFoundDatabaseUuids = $this->allDatabaseUuids->diff($this->foundDatabaseUuids);
        if ($notFoundDatabaseUuids->isEmpty()) {
            return;
        }

        // Only protection: Verify we received any container data at all
        // If containers collection is completely empty, Sentinel might have failed
        if ($this->containers->isEmpty()) {
            return;
        }

        $notFoundDatabaseUuids->each(function ($databaseUuid) {
            $database = $this->databasesByUuid->get($databaseUuid);
            if ($database) {
                if (! str($database->status)->startsWith('exited')) {
                    $database->update([
                        'status' => 'exited',
                        'restart_count' => 0,
                        'last_restart_at' => null,
                        'last_restart_type' => null,
                    ]);
                }
                if ($database->is_public) {
                    StopDatabaseProxy::dispatch($database);
                }
            }
        });
    }

    private function updateNotFoundServiceStatus()
    {
        $notFoundServiceApplicationIds = $this->allServiceApplicationIds->diff($this->foundServiceApplicationIds);
        $notFoundServiceDatabaseIds = $this->allServiceDatabaseIds->diff($this->foundServiceDatabaseIds);

        // Batch update service applications
        if ($notFoundServiceApplicationIds->isNotEmpty()) {
            ServiceApplication::whereIn('id', $notFoundServiceApplicationIds)
                ->where('status', '!=', 'exited')
                ->update(['status' => 'exited']);
        }

        // Batch update service databases
        if ($notFoundServiceDatabaseIds->isNotEmpty()) {
            ServiceDatabase::whereIn('id', $notFoundServiceDatabaseIds)
                ->where('status', '!=', 'exited')
                ->update(['status' => 'exited']);
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

    /**
     * Check if container is in an active or transient state.
     * Active states: running
     * Transient states: restarting, starting, created, paused
     *
     * These states indicate the container exists and should be tracked.
     * Terminal states (exited, dead, removing) should NOT be tracked.
     */
    private function isActiveOrTransient(string $containerStatus): bool
    {
        return str($containerStatus)->contains('running') ||
               str($containerStatus)->contains('restarting') ||
               str($containerStatus)->contains('starting') ||
               str($containerStatus)->contains('created') ||
               str($containerStatus)->contains('paused');
    }

    private function checkLogDrainContainer()
    {
        if ($this->server->isLogDrainEnabled() && $this->foundLogDrainContainer === false) {
            StartLogDrain::dispatch($this->server);
        }
    }
}
