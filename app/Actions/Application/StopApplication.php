<?php

namespace App\Actions\Application;

use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplication
{
    use AsAction;

    public function handle(Application $application, bool $previewDeployments = false)
    {
        if ($application->destination->server->isSwarm()) {
            instant_remote_process(["docker stack rm {$application->uuid}"], $application->destination->server);

            return;
        }

        $servers = collect([]);
        $servers->push($application->destination->server);
        $application->additional_servers->map(function ($server) use ($servers) {
            $servers->push($server);
        });
        foreach ($servers as $server) {
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }
            if ($previewDeployments) {
                $containers = getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $application->id, 0);
            }
            if ($containers->count() > 0) {
                foreach ($containers as $container) {
                    $containerName = data_get($container, 'Names');
                    if ($containerName) {
                        instant_remote_process(command: ["docker stop --time=30 $containerName"], server: $server, throwError: false);
                        instant_remote_process(command: ["docker rm $containerName"], server: $server, throwError: false);
                        instant_remote_process(command: ["docker rm -f {$containerName}"], server: $server, throwError: false);
                    }
                }
            }
            if ($application->build_pack === 'dockercompose') {
                // remove network
                $uuid = $application->uuid;
                instant_remote_process(["docker network disconnect {$uuid} coolify-proxy"], $server, false);
                instant_remote_process(["docker network rm {$uuid}"], $server, false);
            }
        }
    }
}
