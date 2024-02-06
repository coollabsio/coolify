<?php

namespace App\Actions\Application;

use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Notifications\Application\StatusChanged;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplication
{
    use AsAction;
    public function handle(Application $application)
    {
        if ($application->destination->server->isSwarm()) {
            instant_remote_process(["docker stack rm {$application->uuid}"], $application->destination->server);
            return;
        }

        $servers = collect([]);
        $servers->push($application->destination->server);
        $application->additional_networks->map(function ($network) use ($servers) {
            $servers->push($network->server);
        });
        foreach ($servers as $server) {
            if (!$server->isFunctional()) {
                return 'Server is not functional';
            }
            $containers = getCurrentApplicationContainerStatus($server, $application->id, 0);
            if ($containers->count() > 0) {
                foreach ($containers as $container) {
                    $containerName = data_get($container, 'Names');
                    if ($containerName) {
                        instant_remote_process(
                            ["docker rm -f {$containerName}"],
                            $server
                        );
                    }
                }
                // $application->environment->project->team->notify(new StatusChanged($application));
            }
        }

        // // Delete Preview Deployments
        // $previewDeployments = $application->previews;
        // foreach ($previewDeployments as $previewDeployment) {
        //     $containers = getCurrentApplicationContainerStatus($server, $application->id, $previewDeployment->pull_request_id);
        //     foreach ($containers as $container) {
        //         $name = str_replace('/', '', $container['Names']);
        //         instant_remote_process(["docker rm -f $name"], $application->destination->server, throwError: false);
        //     }
        // }
    }
}
