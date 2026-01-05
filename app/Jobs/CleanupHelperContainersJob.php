<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupHelperContainersJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        try {
            // Get all active deployments on this server
            $activeDeployments = ApplicationDeploymentQueue::where('server_id', $this->server->id)
                ->whereIn('status', [
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ApplicationDeploymentStatus::QUEUED->value,
                ])
                ->pluck('deployment_uuid')
                ->toArray();

            \Log::info('CleanupHelperContainersJob - Active deployments', [
                'server' => $this->server->name,
                'active_deployment_uuids' => $activeDeployments,
            ]);

            $containers = instant_remote_process_with_timeout(['docker container ps --format \'{{json .}}\' | jq -s \'map(select(.Image | contains("'.config('constants.coolify.registry_url').'/coollabsio/coolify-helper")))\''], $this->server, false);
            $helperContainers = collect(json_decode($containers));

            if ($helperContainers->count() > 0) {
                foreach ($helperContainers as $container) {
                    $containerId = data_get($container, 'ID');
                    $containerName = data_get($container, 'Names');

                    // Check if this container belongs to an active deployment
                    $isActiveDeployment = false;
                    foreach ($activeDeployments as $deploymentUuid) {
                        if (str_contains($containerName, $deploymentUuid)) {
                            $isActiveDeployment = true;
                            break;
                        }
                    }

                    if ($isActiveDeployment) {
                        \Log::info('CleanupHelperContainersJob - Skipping active deployment container', [
                            'container' => $containerName,
                            'id' => $containerId,
                        ]);

                        continue;
                    }

                    \Log::info('CleanupHelperContainersJob - Removing orphaned helper container', [
                        'container' => $containerName,
                        'id' => $containerId,
                    ]);

                    instant_remote_process_with_timeout(['docker container rm -f '.$containerId], $this->server, false);
                }
            }
        } catch (\Throwable $e) {
            send_internal_notification('CleanupHelperContainersJob failed with error: '.$e->getMessage());
        }
    }
}
