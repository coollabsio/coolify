<?php

namespace App\Actions\Application;

use App\Models\Application;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplicationOneServer
{
    use AsAction;

    public function handle(Application $application, Server $server)
    {
        if ($application->destination->server->isSwarm()) {
            return;
        }
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        try {
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
            }
        } catch (\Exception $e) {
            ray($e->getMessage());

            return $e->getMessage();
        }
    }
}
