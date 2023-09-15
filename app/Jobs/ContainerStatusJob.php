<?php

namespace App\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Notifications\Container\ContainerRestarted;
use App\Notifications\Container\ContainerStopped;
use App\Notifications\Server\Unreachable;
use Arr;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ContainerStatusJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 120;

    public function __construct(public Server $server)
    {
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->server->uuid)];
    }

    public function uniqueId(): string
    {
        return $this->server->uuid;
    }

    private function checkServerConnection() {
        ray("Checking server connection to {$this->server->ip}");
        $uptime = instant_remote_process(['uptime'], $this->server, false);
        if (!is_null($uptime)) {
            ray('Server is up');
            return true;
        }
    }
    public function handle(): void
    {
        try {
            ray()->clearAll();
            $serverUptimeCheckNumber = 0;
            $serverUptimeCheckNumberMax = 5;
            while (true) {
                if ($serverUptimeCheckNumber >= $serverUptimeCheckNumberMax) {
                    $this->server->settings()->update(['is_reachable' => false]);
                    $this->server->team->notify(new Unreachable($this->server));
                    return;
                }
                $result = $this->checkServerConnection();
                if ($result) {
                    break;
                }
                $serverUptimeCheckNumber++;
                sleep(5);
            }
            $containers = instant_remote_process(["docker container inspect $(docker container ls -q) --format '{{json .}}'"], $this->server);
            $containers = format_docker_command_output_to_json($containers);
            $applications = $this->server->applications();
            $databases = $this->server->databases();
            $previews = $this->server->previews();
            if ($this->server->isProxyShouldRun()) {
                $foundProxyContainer = $containers->filter(function ($value, $key) {
                    return data_get($value, 'Name') === '/coolify-proxy';
                })->first();
                if (!$foundProxyContainer) {
                    resolve(StartProxy::class)($this->server, false);
                    $this->server->team->notify(new ContainerRestarted('coolify-proxy', $this->server));
                }
            }
            $foundApplications = [];
            $foundApplicationPreviews = [];
            $foundDatabases = [];
            foreach ($containers as $container) {
                $containerStatus = data_get($container, 'State.Status');
                $labels = data_get($container, 'Config.Labels');
                $labels = Arr::undot(format_docker_labels_to_json($labels));
                $labelId = data_get($labels, 'coolify.applicationId');
                if ($labelId) {
                    if (str_contains($labelId,'-pr-')) {
                        $previewId = (int) Str::after($labelId, '-pr-');
                        $applicationId = (int) Str::before($labelId, '-pr-');
                        $preview = ApplicationPreview::where('application_id', $applicationId)->where('pull_request_id',$previewId)->first();
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
                        $application = $applications->where('id', $labelId)->first();
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
                    if ($uuid) {
                        $database = $databases->where('uuid', $uuid)->first();
                        if ($database) {
                            $foundDatabases[] = $database->id;
                            $statusFromDb = $database->status;
                            if ($statusFromDb !== $containerStatus) {
                                $database->update(['status' => $containerStatus]);
                            }
                        } else {
                            // Notify user that this container should not be there.
                        }
                    }
                }

            }
            $notRunningApplications = $applications->pluck('id')->diff($foundApplications);
            foreach($notRunningApplications as $applicationId) {
                $application = $applications->where('id', $applicationId)->first();
                if ($application->status === 'exited') {
                    continue;
                }
                $application->update(['status' => 'exited']);

                $name = data_get($application, 'name');
                $fqdn = data_get($application, 'fqdn');

                $containerName = $name ? "$name ($fqdn)" : $fqdn;

                $project = data_get($application, 'environment.project');
                $environment = data_get($application, 'environment');

                $url =  base_url() . '/project/' . $project->uuid . "/" . $environment->name . "/application/" . $application->uuid;

                $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
            }
            $notRunningApplicationPreviews = $previews->pluck('id')->diff($foundApplicationPreviews);
            foreach ($notRunningApplicationPreviews as $previewId) {
                $preview = $previews->where('id', $previewId)->first();
                if ($preview->status === 'exited') {
                    continue;
                }
                $preview->update(['status' => 'exited']);

                $name = data_get($preview, 'name');
                $fqdn = data_get($preview, 'fqdn');

                $containerName = $name ? "$name ($fqdn)" : $fqdn;

                $project = data_get($preview, 'application.environment.project');
                $environment = data_get($preview, 'application.environment');

                $url =  base_url() . '/project/' . $project->uuid . "/" . $environment->name . "/application/" . $preview->application->uuid;
                $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
            }
            $notRunningDatabases = $databases->pluck('id')->diff($foundDatabases);
            foreach($notRunningDatabases as $database) {
                $database = $databases->where('id', $database)->first();
                if ($database->status === 'exited') {
                    continue;
                }
                $database->update(['status' => 'exited']);

                   $name = data_get($database, 'name');
                $fqdn = data_get($database, 'fqdn');

                $containerName = $name;

                $project = data_get($database, 'environment.project');
                $environment = data_get($database, 'environment');

                $url =  base_url() . '/project/' . $project->uuid . "/" . $environment->name . "/database/" . $database->uuid;
                $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
            }












            return;
            foreach ($applications as $application) {
                $uuid = data_get($application, 'uuid');
                $id = data_get($application, 'id');
                $foundContainer = $containers->filter(function ($value, $key) use ($id, $uuid) {
                    $labels = data_get($value, 'Config.Labels');
                    $labels = Arr::undot(format_docker_labels_to_json($labels));
                    $labelId = data_get($labels, 'coolify.applicationId');
                    if ($labelId == $id) {
                        return $value;
                    }
                    $isPR = Str::startsWith(data_get($value, 'Name'), "/$uuid");
                    $isPR = Str::contains(data_get($value, 'Name'), "-pr-");
                    if ($isPR) {
                        ray('is pr');
                        return false;
                    }
                    return $value;
                })->first();
                ray($foundContainer);
                if ($foundContainer) {
                    $containerStatus = data_get($foundContainer, 'State.Status');
                    $databaseStatus = data_get($application, 'status');
                    if ($containerStatus !== $databaseStatus) {
                        $application->update(['status' => $containerStatus]);
                    }
                } else {
                    $databaseStatus = data_get($application, 'status');
                    if ($databaseStatus !== 'exited') {
                        $application->update(['status' => 'exited']);
                        $name = data_get($application, 'name');
                        $fqdn = data_get($application, 'fqdn');
                        $containerName = $name ? "$name ($fqdn)" : $fqdn;
                        $project = data_get($application, 'environment.project');
                        $environment = data_get($application, 'environment');
                        $url =  base_url() . '/project/' . $project->uuid . "/" . $environment->name . "/application/" . $application->uuid;
                        $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
                    }
                }
                $previews = $application->previews;
                foreach ($previews as $preview) {
                    $foundContainer = $containers->filter(function ($value, $key) use ($id, $uuid, $preview) {
                        $labels = data_get($value, 'Config.Labels');
                        $labels = Arr::undot(format_docker_labels_to_json($labels));
                        $labelId = data_get($labels, 'coolify.applicationId');
                        if ($labelId == "$id-pr-{$preview->id}") {
                            return $value;
                        }
                        return Str::startsWith(data_get($value, 'Name'), "/$uuid-pr-{$preview->id}");
                    })->first();
                }

            }
            foreach ($databases as $database) {
                $uuid = data_get($database, 'uuid');
                $foundContainer = $containers->filter(function ($value, $key) use ($uuid) {
                    return Str::startsWith(data_get($value, 'Name'), "/$uuid");
                })->first();

                if ($foundContainer) {
                    $containerStatus = data_get($foundContainer, 'State.Status');
                    $databaseStatus = data_get($database, 'status');
                    if ($containerStatus !== $databaseStatus) {
                        $database->update(['status' => $containerStatus]);
                    }
                } else {
                    $databaseStatus = data_get($database, 'status');
                    if ($databaseStatus !== 'exited') {
                        $database->update(['status' => 'exited']);
                        $name = data_get($database, 'name');
                        $containerName = $name;
                        $project = data_get($database, 'environment.project');
                        $environment = data_get($database, 'environment');
                        $url =  base_url() . '/project/' . $project->uuid . "/" . $environment->name . "/database/" . $database->uuid;
                        $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
                    }
                }
            }
           // TODO Monitor other containers not managed by Coolify
        } catch (\Throwable $e) {
            send_internal_notification('ContainerStatusJob failed with: ' . $e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
