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

    /**
     * Containers younger than this are never removed even if classified as orphaned.
     * Acts as a defense-in-depth guard against any remaining race condition between
     * helper container start-up and queue row state transitions.
     */
    private const MIN_ORPHAN_AGE_SECONDS = 300;

    public function handle(): void
    {
        try {
            // Get all active deployments touching this server. A deployment can run
            // its build phase on a dedicated build server (build_server_id) while its
            // runtime is on a different deployment server (server_id), so both
            // columns must be considered when this job runs on the build server.
            // See #7649 / #6648 / #7566.
            $activeDeployments = ApplicationDeploymentQueue::where(function ($q) {
                $q->where('server_id', $this->server->id)
                    ->orWhere('build_server_id', $this->server->id);
            })
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

                    // Defense-in-depth: skip very young helper containers even if
                    // classified as orphaned. A container that started seconds ago
                    // is overwhelmingly likely to belong to a build that simply
                    // hasn't transitioned its queue row yet (or whose
                    // build_server_id assignment hasn't been persisted yet).
                    $ageSeconds = $this->containerAgeInSeconds($containerId);
                    if ($ageSeconds !== null && $ageSeconds < self::MIN_ORPHAN_AGE_SECONDS) {
                        \Log::info('CleanupHelperContainersJob - Skipping young helper container (race-condition guard)', [
                            'container' => $containerName,
                            'id' => $containerId,
                            'age_seconds' => $ageSeconds,
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

    private function containerAgeInSeconds(string $containerId): ?int
    {
        try {
            $output = instant_remote_process_with_timeout(
                ["docker inspect --format '{{.State.StartedAt}}' ".escapeshellarg($containerId)],
                $this->server,
                false
            );
            $startedAt = trim((string) $output);
            if ($startedAt === '') {
                return null;
            }
            $startedTs = strtotime($startedAt);
            if ($startedTs === false) {
                return null;
            }

            return time() - $startedTs;
        } catch (\Throwable) {
            return null;
        }
    }
}
