<?php

namespace App\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PushServerUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server, public $data)
    {
        // TODO: Handle multiple servers
        // TODO: Handle Preview deployments
        // TODO: Handle DB TCP proxies
        // TODO: Handle DBs
        // TODO: Handle services
        // TODO: Handle proxies
    }

    public function handle()
    {
        if (! $this->data) {
            throw new \Exception('No data provided');
        }
        $data = collect($this->data);
        $containers = collect(data_get($data, 'containers'));
        if ($containers->isEmpty()) {
            return;
        }
        $foundApplicationIds = collect();
        $foundServiceIds = collect();
        $foundProxy = false;
        foreach ($containers as $container) {
            $containerStatus = data_get($container, 'state', 'exited');
            $containerHealth = data_get($container, 'health_status', 'unhealthy');
            $containerStatus = "$containerStatus ($containerHealth)";
            $labels = collect(data_get($container, 'labels'));
            $coolify_managed = $labels->has('coolify.managed');
            if ($coolify_managed) {
                if ($labels->has('coolify.applicationId')) {
                    $applicationId = $labels->get('coolify.applicationId');
                    $pullRequestId = data_get($labels, 'coolify.pullRequestId', '0');
                    $foundApplicationIds->push($applicationId);
                    try {
                        $this->updateApplicationStatus($applicationId, $pullRequestId, $containerStatus);
                    } catch (\Exception $e) {
                        Log::error($e);
                    }
                } elseif ($labels->has('coolify.serviceId')) {
                    $serviceId = $labels->get('coolify.serviceId');
                    $foundServiceIds->push($serviceId);
                    Log::info("Service: $serviceId, $containerStatus");
                } else {
                    $name = data_get($container, 'name');
                    $uuid = $labels->get('com.docker.compose.service');
                    $type = $labels->get('coolify.type');
                    if ($name === 'coolify-proxy') {
                        $foundProxy = true;
                        Log::info("Proxy: $uuid, $containerStatus");
                    } elseif ($type === 'service') {
                        Log::info("Service: $uuid, $containerStatus");
                    } else {
                        Log::info("Database: $uuid, $containerStatus");
                    }
                }
            }
        }

        // If proxy is not found, start it
        if (! $foundProxy && $this->server->isProxyShouldRun()) {
            Log::info('Proxy not found, starting it');
            StartProxy::dispatch($this->server);
        }

        // Update not found applications
        $allApplicationIds = $this->server->applications()->pluck('id');
        $notFoundApplicationIds = $allApplicationIds->diff($foundApplicationIds);
        if ($notFoundApplicationIds->isNotEmpty()) {
            Log::info('Not found application ids', ['application_ids' => $notFoundApplicationIds]);
            $this->updateNotFoundApplications($notFoundApplicationIds);
        }
    }

    private function updateApplicationStatus(string $applicationId, string $pullRequestId, string $containerStatus)
    {
        if ($pullRequestId === '0') {
            $application = Application::find($applicationId);
            if (! $application) {
                return;
            }
            $application->status = $containerStatus;
            $application->save();
            Log::info('Application updated', ['application_id' => $applicationId, 'status' => $containerStatus]);
        } else {
            $application = ApplicationPreview::where('application_id', $applicationId)->where('pull_request_id', $pullRequestId)->first();
            if (! $application) {
                return;
            }
            $application->status = $containerStatus;
            $application->save();
        }
    }

    private function updateNotFoundApplications(Collection $applicationIds)
    {
        $applicationIds->each(function ($applicationId) {
            Log::info('Updating application status', ['application_id' => $applicationId, 'status' => 'exited']);
            $application = Application::find($applicationId);
            if ($application) {
                $application->status = 'exited';
                $application->save();
                Log::info('Application status updated', ['application_id' => $applicationId, 'status' => 'exited']);
            }
        });
    }
}
