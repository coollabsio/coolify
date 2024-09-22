<?php

namespace App\Actions\Application;

use App\Models\Application;
use App\Actions\Server\CleanupDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplication
{
    use AsAction;

    public function handle(Application $application, bool $previewDeployments = false, bool $dockerCleanup = true)
    {
        try {
            $server = $application->destination->server;
            if (!$server->isFunctional()) {
                return 'Server is not functional';
            }
            ray('Stopping application: ' . $application->name);

            if ($server->isSwarm()) {
                instant_remote_process(["docker stack rm {$application->uuid}"], $server);
                return;
            }

            $containersToStop = $application->getContainersToStop($previewDeployments);
            $application->stopContainers($containersToStop, $server);

            if ($application->build_pack === 'dockercompose') {
                $application->delete_connected_networks($application->uuid);
            }

            if ($dockerCleanup) {
                CleanupDocker::run($server, true);
            }
        } catch (\Exception $e) {
            ray($e->getMessage());
            return $e->getMessage();
        }
    }
}
