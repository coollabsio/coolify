<?php

namespace App\Jobs;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Shared\ComplexStatusCheck;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PushServerUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 30;

    public Collection $containers;

    public Collection $allApplicationIds;

    public Collection $allDatabaseUuids;

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

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server, public $data)
    {
        // TODO: Handle multiple servers - done - NOT TESTED
        // TODO: Handle Preview deployments - done - NOT TESTED
        // TODO: Emails
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
        if (! $this->data) {
            throw new \Exception('No data provided');
        }
        $data = collect($this->data);
        $this->containers = collect(data_get($data, 'containers'));
        if ($this->containers->isEmpty()) {
            return;
        }
        $this->allApplicationIds = $this->server->applications()
            ->filter(function ($application) {
                return $application->additional_servers->count() === 0;
            })
            ->pluck('id');
        $this->allApplicationsWithAdditionalServers = $this->server->applications()
            ->filter(function ($application) {
                return $application->additional_servers->count() > 0;
            });
        $this->allApplicationPreviewsIds = $this->server->previews()->pluck('id');
        $this->allDatabaseUuids = $this->server->databases()->pluck('uuid');
        $this->allTcpProxyUuids = $this->server->databases()->where('is_public', true)->pluck('uuid');
        $this->server->services()->each(function ($service) {
            $service->applications()->pluck('id')->each(function ($applicationId) {
                $this->allServiceApplicationIds->push($applicationId);
            });
            $service->databases()->pluck('id')->each(function ($databaseId) {
                $this->allServiceDatabaseIds->push($databaseId);
            });
        });

        logger('allServiceApplicationIds', ['allServiceApplicationIds' => $this->allServiceApplicationIds]);

        foreach ($this->containers as $container) {
            $containerStatus = data_get($container, 'state', 'exited');
            $containerHealth = data_get($container, 'health_status', 'unhealthy');
            $containerStatus = "$containerStatus ($containerHealth)";
            $labels = collect(data_get($container, 'labels'));
            $coolify_managed = $labels->has('coolify.managed');
            if ($coolify_managed) {
                if ($labels->has('coolify.applicationId')) {
                    $applicationId = $labels->get('coolify.applicationId');
                    $pullRequestId = data_get($labels, 'coolify.pullRequestId', '0');
                    try {
                        if ($pullRequestId === '0') {
                            if ($this->allApplicationIds->contains($applicationId)) {
                                $this->foundApplicationIds->push($applicationId);
                            }
                            $this->updateApplicationStatus($applicationId, $containerStatus);
                        } else {
                            if ($this->allApplicationPreviewsIds->contains($applicationId)) {
                                $this->foundApplicationPreviewsIds->push($applicationId);
                            }
                            $this->updateApplicationPreviewStatus($applicationId, $containerStatus);
                        }
                    } catch (\Exception $e) {
                        logger()->error($e);
                    }
                } elseif ($labels->has('coolify.serviceId')) {
                    $serviceId = $labels->get('coolify.serviceId');
                    $subType = $labels->get('coolify.service.subType');
                    $subId = $labels->get('coolify.service.subId');
                    if ($subType === 'application') {
                        $this->foundServiceApplicationIds->push($subId);
                        $this->updateServiceSubStatus($serviceId, $subType, $subId, $containerStatus);
                    } elseif ($subType === 'database') {
                        $this->foundServiceDatabaseIds->push($subId);
                        $this->updateServiceSubStatus($serviceId, $subType, $subId, $containerStatus);
                    }

                } else {
                    $name = data_get($container, 'name');
                    $uuid = $labels->get('com.docker.compose.service');
                    $type = $labels->get('coolify.type');
                    if ($name === 'coolify-proxy') {
                        logger("Proxy: $uuid, $containerStatus");
                        if (str($containerStatus)->contains('running')) {
                            $this->foundProxy = true;
                        }
                    } elseif ($type === 'service') {
                        logger("Service: $uuid, $containerStatus");
                    } else {
                        if ($this->allDatabaseUuids->contains($uuid)) {
                            $this->foundDatabaseUuids->push($uuid);
                            if ($this->allTcpProxyUuids->contains($uuid)) {
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
    }

    private function updateApplicationStatus(string $applicationId, string $containerStatus)
    {
        $application = $this->server->applications()->where('id', $applicationId)->first();
        if (! $application) {
            return;
        }
        $application->status = $containerStatus;
        $application->save();
        logger('Application updated', ['application_id' => $applicationId, 'status' => $containerStatus]);
    }

    private function updateApplicationPreviewStatus(string $applicationId, string $containerStatus)
    {
        $application = $this->server->previews()->where('id', $applicationId)->first();
        if (! $application) {
            return;
        }
        $application->status = $containerStatus;
        $application->save();
        logger('Application preview updated', ['application_id' => $applicationId, 'status' => $containerStatus]);
    }

    private function updateNotFoundApplicationStatus()
    {
        $notFoundApplicationIds = $this->allApplicationIds->diff($this->foundApplicationIds);
        if ($notFoundApplicationIds->isNotEmpty()) {
            logger('Not found application ids', ['application_ids' => $notFoundApplicationIds]);
            $notFoundApplicationIds->each(function ($applicationId) {
                logger('Updating application status', ['application_id' => $applicationId, 'status' => 'exited']);
                $application = Application::find($applicationId);
                if ($application) {
                    $application->status = 'exited';
                    $application->save();
                    logger('Application status updated', ['application_id' => $applicationId, 'status' => 'exited']);
                }
            });
        }
    }

    private function updateNotFoundApplicationPreviewStatus()
    {
        $notFoundApplicationPreviewsIds = $this->allApplicationPreviewsIds->diff($this->foundApplicationPreviewsIds);
        if ($notFoundApplicationPreviewsIds->isNotEmpty()) {
            logger('Not found application previews ids', ['application_previews_ids' => $notFoundApplicationPreviewsIds]);
            $notFoundApplicationPreviewsIds->each(function ($applicationPreviewId) {
                logger('Updating application preview status', ['application_preview_id' => $applicationPreviewId, 'status' => 'exited']);
                $applicationPreview = ApplicationPreview::find($applicationPreviewId);
                if ($applicationPreview) {
                    $applicationPreview->status = 'exited';
                    $applicationPreview->save();
                    logger('Application preview status updated', ['application_preview_id' => $applicationPreviewId, 'status' => 'exited']);
                }
            });
        }
    }

    private function updateProxyStatus()
    {
        // If proxy is not found, start it
        logger('Proxy not found', ['foundProxy' => $this->foundProxy, 'isProxyShouldRun' => $this->server->isProxyShouldRun()]);
        if (! $this->foundProxy && $this->server->isProxyShouldRun()) {
            logger('Proxy not found, starting it.');
            StartProxy::dispatch($this->server);
        }

    }

    private function updateDatabaseStatus(string $databaseUuid, string $containerStatus, bool $tcpProxy = false)
    {
        $database = $this->server->databases()->where('uuid', $databaseUuid)->first();
        if (! $database) {
            return;
        }
        $database->status = $containerStatus;
        $database->save();
        logger('Database status updated', ['database_uuid' => $databaseUuid, 'status' => $containerStatus]);
        if (str($containerStatus)->contains('running') && $tcpProxy) {
            $tcpProxyContainerFound = $this->containers->filter(function ($value, $key) use ($databaseUuid) {
                return data_get($value, 'name') === "$databaseUuid-proxy" && data_get($value, 'state') === 'running';
            })->first();
            logger('TCP proxy container found', ['tcpProxyContainerFound' => $tcpProxyContainerFound]);
            if (! $tcpProxyContainerFound) {
                logger('Starting TCP proxy for database', ['database_uuid' => $databaseUuid]);
                StartDatabaseProxy::dispatch($database);
            } else {
                logger('TCP proxy for database found in containers', ['database_uuid' => $databaseUuid]);
            }
        }
    }

    private function updateNotFoundDatabaseStatus()
    {
        $notFoundDatabaseUuids = $this->allDatabaseUuids->diff($this->foundDatabaseUuids);
        if ($notFoundDatabaseUuids->isNotEmpty()) {
            logger('Not found database uuids', ['database_uuids' => $notFoundDatabaseUuids]);
            $notFoundDatabaseUuids->each(function ($databaseUuid) {
                logger('Updating database status', ['database_uuid' => $databaseUuid, 'status' => 'exited']);
                $database = $this->server->databases()->where('uuid', $databaseUuid)->first();
                if ($database) {
                    $database->status = 'exited';
                    $database->save();
                    logger('Database status updated', ['database_uuid' => $databaseUuid, 'status' => 'exited']);
                }
            });
        }
    }

    private function updateServiceSubStatus(string $serviceId, string $subType, string $subId, string $containerStatus)
    {
        $service = $this->server->services()->where('id', $serviceId)->first();
        if (! $service) {
            return;
        }
        if ($subType === 'application') {
            $application = $service->applications()->where('id', $subId)->first();
            $application->status = $containerStatus;
            $application->save();
            logger('Service application updated', ['service_id' => $serviceId, 'sub_type' => $subType, 'sub_id' => $subId, 'status' => $containerStatus]);
        } elseif ($subType === 'database') {
            $database = $service->databases()->where('id', $subId)->first();
            $database->status = $containerStatus;
            $database->save();
            logger('Service database updated', ['service_id' => $serviceId, 'sub_type' => $subType, 'sub_id' => $subId, 'status' => $containerStatus]);
        } else {
            logger()->warning('Unknown sub type', ['service_id' => $serviceId, 'sub_type' => $subType, 'sub_id' => $subId, 'status' => $containerStatus]);
        }
    }

    private function updateNotFoundServiceStatus()
    {
        $notFoundServiceApplicationIds = $this->allServiceApplicationIds->diff($this->foundServiceApplicationIds);
        $notFoundServiceDatabaseIds = $this->allServiceDatabaseIds->diff($this->foundServiceDatabaseIds);
        if ($notFoundServiceApplicationIds->isNotEmpty()) {
            logger('Not found service application ids', ['service_application_ids' => $notFoundServiceApplicationIds]);
            $notFoundServiceApplicationIds->each(function ($serviceApplicationId) {
                logger('Updating service application status', ['service_application_id' => $serviceApplicationId, 'status' => 'exited']);
                $application = ServiceApplication::find($serviceApplicationId);
                if ($application) {
                    $application->status = 'exited';
                    $application->save();
                    logger('Service application status updated', ['service_application_id' => $serviceApplicationId, 'status' => 'exited']);
                }
            });
        }
        if ($notFoundServiceDatabaseIds->isNotEmpty()) {
            logger('Not found service database ids', ['service_database_ids' => $notFoundServiceDatabaseIds]);
            $notFoundServiceDatabaseIds->each(function ($serviceDatabaseId) {
                logger('Updating service database status', ['service_database_id' => $serviceDatabaseId, 'status' => 'exited']);
                $database = ServiceDatabase::find($serviceDatabaseId);
                if ($database) {
                    $database->status = 'exited';
                    $database->save();
                    logger('Service database status updated', ['service_database_id' => $serviceDatabaseId, 'status' => 'exited']);
                }
            });
        }
    }

    private function updateAdditionalServersStatus()
    {
        $this->allApplicationsWithAdditionalServers->each(function ($application) {
            logger('Updating additional servers status for application', ['application_id' => $application->id]);
            ComplexStatusCheck::run($application);
        });
    }
}
