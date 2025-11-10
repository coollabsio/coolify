<?php

namespace App\Actions\Docker;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Shared\ComplexStatusCheck;
use App\Events\ServiceChecked;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServiceDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class GetContainersStatus
{
    use AsAction;

    public string $jobQueue = 'high';

    public $applications;

    public ?Collection $containers;

    public ?Collection $containerReplicates;

    public $server;

    protected ?Collection $applicationContainerStatuses;

    protected ?Collection $applicationContainerRestartCounts;

    public function handle(Server $server, ?Collection $containers = null, ?Collection $containerReplicates = null)
    {
        $this->containers = $containers;
        $this->containerReplicates = $containerReplicates;
        $this->server = $server;
        if (! $this->server->isFunctional()) {
            return 'Server is not functional.';
        }
        $this->applications = $this->server->applications();
        $skip_these_applications = collect([]);
        foreach ($this->applications as $application) {
            if ($application->additional_servers->count() > 0) {
                $skip_these_applications->push($application);
                ComplexStatusCheck::run($application);
                $this->applications = $this->applications->filter(function ($value, $key) use ($application) {
                    return $value->id !== $application->id;
                });
            }
        }
        $this->applications = $this->applications->filter(function ($value, $key) use ($skip_these_applications) {
            return ! $skip_these_applications->pluck('id')->contains($value->id);
        });
        if ($this->containers === null) {
            ['containers' => $this->containers, 'containerReplicates' => $this->containerReplicates] = $this->server->getContainers();
        }

        if (is_null($this->containers)) {
            return;
        }

        if ($this->containerReplicates) {
            foreach ($this->containerReplicates as $containerReplica) {
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
        $databases = $this->server->databases();
        $services = $this->server->services()->get();
        $previews = $this->server->previews();
        $foundApplications = [];
        $foundApplicationPreviews = [];
        $foundDatabases = [];
        $foundServices = [];

        foreach ($this->containers as $container) {
            if ($this->server->isSwarm()) {
                $labels = data_get($container, 'Spec.Labels');
                $uuid = data_get($labels, 'coolify.name');
            } else {
                $labels = data_get($container, 'Config.Labels');
            }
            $containerStatus = data_get($container, 'State.Status');
            $containerHealth = data_get($container, 'State.Health.Status', 'unhealthy');
            if ($containerStatus === 'restarting') {
                $containerStatus = "restarting ($containerHealth)";
            } else {
                $containerStatus = "$containerStatus ($containerHealth)";
            }
            $labels = Arr::undot(format_docker_labels_to_json($labels));
            $applicationId = data_get($labels, 'coolify.applicationId');
            if ($applicationId) {
                $pullRequestId = data_get($labels, 'coolify.pullRequestId');
                if ($pullRequestId) {
                    if (str($applicationId)->contains('-')) {
                        $applicationId = str($applicationId)->before('-');
                    }
                    $preview = ApplicationPreview::where('application_id', $applicationId)->where('pull_request_id', $pullRequestId)->first();
                    if ($preview) {
                        $foundApplicationPreviews[] = $preview->id;
                        $statusFromDb = $preview->status;
                        if ($statusFromDb !== $containerStatus) {
                            $preview->update(['status' => $containerStatus]);
                        } else {
                            $preview->update(['last_online_at' => now()]);
                        }
                    } else {
                        // Notify user that this container should not be there.
                    }
                } else {
                    $application = $this->applications->where('id', $applicationId)->first();
                    if ($application) {
                        $foundApplications[] = $application->id;
                        // Store container status for aggregation
                        if (! isset($this->applicationContainerStatuses)) {
                            $this->applicationContainerStatuses = collect();
                        }
                        if (! $this->applicationContainerStatuses->has($applicationId)) {
                            $this->applicationContainerStatuses->put($applicationId, collect());
                        }
                        $containerName = data_get($labels, 'com.docker.compose.service');
                        if ($containerName) {
                            $this->applicationContainerStatuses->get($applicationId)->put($containerName, $containerStatus);
                        }

                        // Track restart counts for applications
                        $restartCount = data_get($container, 'RestartCount', 0);
                        if (! isset($this->applicationContainerRestartCounts)) {
                            $this->applicationContainerRestartCounts = collect();
                        }
                        if (! $this->applicationContainerRestartCounts->has($applicationId)) {
                            $this->applicationContainerRestartCounts->put($applicationId, collect());
                        }
                        if ($containerName) {
                            $this->applicationContainerRestartCounts->get($applicationId)->put($containerName, $restartCount);
                        }
                    } else {
                        // Notify user that this container should not be there.
                    }
                }
            } else {
                $uuid = data_get($labels, 'com.docker.compose.service');
                $type = data_get($labels, 'coolify.type');

                if ($uuid) {
                    if ($type === 'service') {
                        $database_id = data_get($labels, 'coolify.service.subId');
                        if ($database_id) {
                            $service_db = ServiceDatabase::where('id', $database_id)->first();
                            if ($service_db) {
                                $uuid = data_get($service_db, 'service.uuid');
                                if ($uuid) {
                                    $isPublic = data_get($service_db, 'is_public');
                                    if ($isPublic) {
                                        $foundTcpProxy = $this->containers->filter(function ($value, $key) use ($uuid) {
                                            if ($this->server->isSwarm()) {
                                                return data_get($value, 'Spec.Name') === "coolify-proxy_$uuid";
                                            } else {
                                                return data_get($value, 'Name') === "/$uuid-proxy";
                                            }
                                        })->first();
                                        if (! $foundTcpProxy) {
                                            StartDatabaseProxy::run($service_db);
                                            // $this->server->team?->notify(new ContainerRestarted("TCP Proxy for {$service_db->service->name}", $this->server));
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $database = $databases->where('uuid', $uuid)->first();
                        if ($database) {
                            $isPublic = data_get($database, 'is_public');
                            $foundDatabases[] = $database->id;
                            $statusFromDb = $database->status;
                            if ($statusFromDb !== $containerStatus) {
                                $database->update(['status' => $containerStatus]);
                            } else {
                                $database->update(['last_online_at' => now()]);
                            }

                            if ($isPublic) {
                                $foundTcpProxy = $this->containers->filter(function ($value, $key) use ($uuid) {
                                    if ($this->server->isSwarm()) {
                                        return data_get($value, 'Spec.Name') === "coolify-proxy_$uuid";
                                    } else {
                                        return data_get($value, 'Name') === "/$uuid-proxy";
                                    }
                                })->first();
                                if (! $foundTcpProxy) {
                                    StartDatabaseProxy::run($database);
                                    // $this->server->team?->notify(new ContainerRestarted("TCP Proxy for database", $this->server));
                                }
                            }
                        } else {
                            // Notify user that this container should not be there.
                        }
                    }
                }
                if (data_get($container, 'Name') === '/coolify-db') {
                    $foundDatabases[] = 0;
                }
            }
            $serviceLabelId = data_get($labels, 'coolify.serviceId');
            if ($serviceLabelId) {
                $subType = data_get($labels, 'coolify.service.subType');
                $subId = data_get($labels, 'coolify.service.subId');
                $service = $services->where('id', $serviceLabelId)->first();
                if (! $service) {
                    continue;
                }
                if ($subType === 'application') {
                    $service = $service->applications()->where('id', $subId)->first();
                } else {
                    $service = $service->databases()->where('id', $subId)->first();
                }
                if ($service) {
                    $foundServices[] = "$service->id-$service->name";
                    $statusFromDb = $service->status;
                    if ($statusFromDb !== $containerStatus) {
                        $service->update(['status' => $containerStatus]);
                    } else {
                        $service->update(['last_online_at' => now()]);
                    }
                }
            }
        }
        $exitedServices = collect([]);
        foreach ($services as $service) {
            $apps = $service->applications()->get();
            $dbs = $service->databases()->get();
            foreach ($apps as $app) {
                if (in_array("$app->id-$app->name", $foundServices)) {
                    continue;
                } else {
                    $exitedServices->push($app);
                }
            }
            foreach ($dbs as $db) {
                if (in_array("$db->id-$db->name", $foundServices)) {
                    continue;
                } else {
                    $exitedServices->push($db);
                }
            }
        }
        $exitedServices = $exitedServices->unique('uuid');
        foreach ($exitedServices as $exitedService) {
            if (str($exitedService->status)->startsWith('exited')) {
                continue;
            }
            $name = data_get($exitedService, 'name');
            $fqdn = data_get($exitedService, 'fqdn');
            if ($name) {
                if ($fqdn) {
                    $containerName = "$name, available at $fqdn";
                } else {
                    $containerName = $name;
                }
            } else {
                if ($fqdn) {
                    $containerName = $fqdn;
                } else {
                    $containerName = null;
                }
            }
            $projectUuid = data_get($service, 'environment.project.uuid');
            $serviceUuid = data_get($service, 'uuid');
            $environmentName = data_get($service, 'environment.name');

            if ($projectUuid && $serviceUuid && $environmentName) {
                $url = base_url().'/project/'.$projectUuid.'/'.$environmentName.'/service/'.$serviceUuid;
            } else {
                $url = null;
            }
            // $this->server->team?->notify(new ContainerStopped($containerName, $this->server, $url));
            $exitedService->update(['status' => 'exited']);
        }

        $notRunningApplications = $this->applications->pluck('id')->diff($foundApplications);
        foreach ($notRunningApplications as $applicationId) {
            $application = $this->applications->where('id', $applicationId)->first();
            if (str($application->status)->startsWith('exited')) {
                continue;
            }

            // Only protection: If no containers at all, Docker query might have failed
            if ($this->containers->isEmpty()) {
                continue;
            }

            // If container was recently restarting (crash loop), keep it as degraded for a grace period
            // This prevents false "exited" status during the brief moment between container removal and recreation
            $recentlyRestarted = $application->restart_count > 0 &&
                                 $application->last_restart_at &&
                                 $application->last_restart_at->greaterThan(now()->subSeconds(30));

            if ($recentlyRestarted) {
                // Keep it as degraded if it was recently in a crash loop
                $application->update(['status' => 'degraded (unhealthy)']);
            } else {
                // Reset restart count when application exits completely
                $application->update([
                    'status' => 'exited',
                    'restart_count' => 0,
                    'last_restart_at' => null,
                    'last_restart_type' => null,
                ]);
            }
        }
        $notRunningApplicationPreviews = $previews->pluck('id')->diff($foundApplicationPreviews);
        foreach ($notRunningApplicationPreviews as $previewId) {
            $preview = $previews->where('id', $previewId)->first();
            if (str($preview->status)->startsWith('exited')) {
                continue;
            }

            // Only protection: If no containers at all, Docker query might have failed
            if ($this->containers->isEmpty()) {
                continue;
            }

            $preview->update(['status' => 'exited']);
        }
        $notRunningDatabases = $databases->pluck('id')->diff($foundDatabases);
        foreach ($notRunningDatabases as $database) {
            $database = $databases->where('id', $database)->first();
            if (str($database->status)->startsWith('exited')) {
                continue;
            }
            $database->update(['status' => 'exited']);

            $name = data_get($database, 'name');
            $fqdn = data_get($database, 'fqdn');

            $containerName = $name;

            $projectUuid = data_get($database, 'environment.project.uuid');
            $environmentName = data_get($database, 'environment.name');
            $databaseUuid = data_get($database, 'uuid');

            if ($projectUuid && $databaseUuid && $environmentName) {
                $url = base_url().'/project/'.$projectUuid.'/'.$environmentName.'/database/'.$databaseUuid;
            } else {
                $url = null;
            }
            // $this->server->team?->notify(new ContainerStopped($containerName, $this->server, $url));
        }

        // Aggregate multi-container application statuses
        if (isset($this->applicationContainerStatuses) && $this->applicationContainerStatuses->isNotEmpty()) {
            foreach ($this->applicationContainerStatuses as $applicationId => $containerStatuses) {
                $application = $this->applications->where('id', $applicationId)->first();
                if (! $application) {
                    continue;
                }

                // Track restart counts first
                $maxRestartCount = 0;
                if (isset($this->applicationContainerRestartCounts) && $this->applicationContainerRestartCounts->has($applicationId)) {
                    $containerRestartCounts = $this->applicationContainerRestartCounts->get($applicationId);
                    $maxRestartCount = $containerRestartCounts->max() ?? 0;
                }

                // Wrap all database updates in a transaction to ensure consistency
                DB::transaction(function () use ($application, $maxRestartCount, $containerStatuses) {
                    $previousRestartCount = $application->restart_count ?? 0;

                    if ($maxRestartCount > $previousRestartCount) {
                        // Restart count increased - this is a crash restart
                        $application->update([
                            'restart_count' => $maxRestartCount,
                            'last_restart_at' => now(),
                            'last_restart_type' => 'crash',
                        ]);

                        // Send notification
                        $containerName = $application->name;
                        $projectUuid = data_get($application, 'environment.project.uuid');
                        $environmentName = data_get($application, 'environment.name');
                        $applicationUuid = data_get($application, 'uuid');

                        if ($projectUuid && $applicationUuid && $environmentName) {
                            $url = base_url().'/project/'.$projectUuid.'/'.$environmentName.'/application/'.$applicationUuid;
                        } else {
                            $url = null;
                        }
                    }

                    // Aggregate status after tracking restart counts
                    $aggregatedStatus = $this->aggregateApplicationStatus($application, $containerStatuses, $maxRestartCount);
                    if ($aggregatedStatus) {
                        $statusFromDb = $application->status;
                        if ($statusFromDb !== $aggregatedStatus) {
                            $application->update(['status' => $aggregatedStatus]);
                        } else {
                            $application->update(['last_online_at' => now()]);
                        }
                    }
                });
            }
        }

        ServiceChecked::dispatch($this->server->team->id);
    }

    private function aggregateApplicationStatus($application, Collection $containerStatuses, int $maxRestartCount = 0): ?string
    {
        // Parse docker compose to check for excluded containers
        $dockerComposeRaw = data_get($application, 'docker_compose_raw');
        $excludedContainers = collect();

        if ($dockerComposeRaw) {
            try {
                $dockerCompose = \Symfony\Component\Yaml\Yaml::parse($dockerComposeRaw);
                $services = data_get($dockerCompose, 'services', []);

                foreach ($services as $serviceName => $serviceConfig) {
                    // Check if container should be excluded
                    $excludeFromHc = data_get($serviceConfig, 'exclude_from_hc', false);
                    $restartPolicy = data_get($serviceConfig, 'restart', 'always');

                    if ($excludeFromHc || $restartPolicy === 'no') {
                        $excludedContainers->push($serviceName);
                    }
                }
            } catch (\Exception $e) {
                // If we can't parse, treat all containers as included
            }
        }

        // Filter out excluded containers
        $relevantStatuses = $containerStatuses->filter(function ($status, $containerName) use ($excludedContainers) {
            return ! $excludedContainers->contains($containerName);
        });

        // If all containers are excluded, don't update status
        if ($relevantStatuses->isEmpty()) {
            return null;
        }

        $hasRunning = false;
        $hasRestarting = false;
        $hasUnhealthy = false;
        $hasExited = false;

        foreach ($relevantStatuses as $status) {
            if (str($status)->contains('restarting')) {
                $hasRestarting = true;
            } elseif (str($status)->contains('running')) {
                $hasRunning = true;
                if (str($status)->contains('unhealthy')) {
                    $hasUnhealthy = true;
                }
            } elseif (str($status)->contains('exited')) {
                $hasExited = true;
                $hasUnhealthy = true;
            }
        }

        if ($hasRestarting) {
            return 'degraded (unhealthy)';
        }

        // If container is exited but has restart count > 0, it's in a crash loop
        if ($hasExited && $maxRestartCount > 0) {
            return 'degraded (unhealthy)';
        }

        if ($hasRunning && $hasExited) {
            return 'degraded (unhealthy)';
        }

        if ($hasRunning) {
            return $hasUnhealthy ? 'running (unhealthy)' : 'running (healthy)';
        }

        // All containers are exited with no restart count - truly stopped
        return 'exited (unhealthy)';
    }
}
