<?php

namespace App\Actions\Application;

use App\Actions\Server\CleanupDocker;
use App\Events\ServiceStatusChanged;
use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplication
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Application $application, bool $previewDeployments = false, bool $dockerCleanup = true)
    {
        $servers = collect([$application->destination->server]);
        if ($application?->additional_servers?->count() > 0) {
            $servers = $servers->merge($application->additional_servers);
        }
        foreach ($servers as $server) {
            try {
                if (! $server->isFunctional()) {
                    return 'Server is not functional';
                }

                if ($server->isSwarm()) {
                    instant_remote_process(["docker stack rm {$application->uuid}"], $server);

                    return;
                }

                $containers = $previewDeployments
                    ? getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true)
                    : getCurrentApplicationContainerStatus($server, $application->id, 0);

                $containersToStop = $containers->pluck('Names')->toArray();

                foreach ($containersToStop as $containerName) {
                    instant_remote_process(command: [
                        "docker stop -t 30 $containerName",
                        "docker rm -f $containerName",
                    ], server: $server, throwError: false);
                }

                if ($application->build_pack === 'dockercompose') {
                    $application->deleteConnectedNetworks();
                }

                if ($dockerCleanup) {
                    CleanupDocker::dispatch($server, false, false);
                }
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }
        ServiceStatusChanged::dispatch($application->environment->project->team->id);
    }
}
