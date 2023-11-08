<?php

namespace App\Actions\Application;

use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplication
{
    use AsAction;
    public function handle(Application $application)
    {
        $server = $application->destination->server;
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
            // TODO: make notification for application
            // $application->environment->project->team->notify(new StatusChanged($application));
        }
    }
}
