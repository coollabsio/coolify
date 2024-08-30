<?php

namespace App\Jobs;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Docker\GetContainersStatus;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\InstallLogDrain;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Notifications\Container\ContainerRestarted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ServerCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

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

    // public function middleware(): array
    // {
    //     return [(new WithoutOverlapping($this->server->uuid))];
    // }

    // public function uniqueId(): int
    // {
    //     return $this->server->uuid;
    // }

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
                GetContainersStatus::run($this->server, $this->containers, $containerReplicates);
                $this->checkLogDrainContainer();
            }

        } catch (\Throwable $e) {
            ray($e->getMessage());

            return handleError($e);
        }

    }

    private function serverStatus()
    {
        ['uptime' => $uptime] = $this->server->validateConnection();
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
        if(! $this->server->isLogDrainEnabled()) {
            return;
        }
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

    private function containerStatus()
    {

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
            $containerStatus = "$containerStatus ($containerHealth)";
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
                        }
                    } else {
                        //Notify user that this container should not be there.
                    }
                } else {
                    $application = $this->applications->where('id', $applicationId)->first();
                    if ($application) {
                        $foundApplications[] = $application->id;
                        $statusFromDb = $application->status;
                        if ($statusFromDb !== $containerStatus) {
                            $application->update(['status' => $containerStatus]);
                        }
                    } else {
                        //Notify user that this container should not be there.
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
                        $database = $this->databases->where('uuid', $uuid)->first();
                        if ($database) {
                            $isPublic = data_get($database, 'is_public');
                            $foundDatabases[] = $database->id;
                            $statusFromDb = $database->status;
                            if ($statusFromDb !== $containerStatus) {
                                $database->update(['status' => $containerStatus]);
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
                                    $this->server->team?->notify(new ContainerRestarted("TCP Proxy for {$database->name}", $this->server));
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
                $service = $this->services->where('id', $serviceLabelId)->first();
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
                        // ray('Updating status: ' . $containerStatus);
                        $service->update(['status' => $containerStatus]);
                    }
                }
            }
        }
        $exitedServices = collect([]);
        foreach ($this->services as $service) {
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
        $exitedServices = $exitedServices->unique('id');
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
            $application->update(['status' => 'exited']);

            $name = data_get($application, 'name');
            $fqdn = data_get($application, 'fqdn');

            $containerName = $name ? "$name ($fqdn)" : $fqdn;

            $projectUuid = data_get($application, 'environment.project.uuid');
            $applicationUuid = data_get($application, 'uuid');
            $environment = data_get($application, 'environment.name');

            if ($projectUuid && $applicationUuid && $environment) {
                $url = base_url().'/project/'.$projectUuid.'/'.$environment.'/application/'.$applicationUuid;
            } else {
                $url = null;
            }

            // $this->server->team?->notify(new ContainerStopped($containerName, $this->server, $url));
        }
        $notRunningApplicationPreviews = $this->previews->pluck('id')->diff($foundApplicationPreviews);
        foreach ($notRunningApplicationPreviews as $previewId) {
            $preview = $this->previews->where('id', $previewId)->first();
            if (str($preview->status)->startsWith('exited')) {
                continue;
            }
            $preview->update(['status' => 'exited']);

            $name = data_get($preview, 'name');
            $fqdn = data_get($preview, 'fqdn');

            $containerName = $name ? "$name ($fqdn)" : $fqdn;

            $projectUuid = data_get($preview, 'application.environment.project.uuid');
            $environmentName = data_get($preview, 'application.environment.name');
            $applicationUuid = data_get($preview, 'application.uuid');

            if ($projectUuid && $applicationUuid && $environmentName) {
                $url = base_url().'/project/'.$projectUuid.'/'.$environmentName.'/application/'.$applicationUuid;
            } else {
                $url = null;
            }

            // $this->server->team?->notify(new ContainerStopped($containerName, $this->server, $url));
        }
        $notRunningDatabases = $this->databases->pluck('id')->diff($foundDatabases);
        foreach ($notRunningDatabases as $database) {
            $database = $this->databases->where('id', $database)->first();
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

        // Check if proxy is running
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
