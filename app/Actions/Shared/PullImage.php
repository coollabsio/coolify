<?php

namespace App\Actions\Shared;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;

class PullImage
{
    use AsAction;

    public function handle(Service $service)
    {
        $service->saveComposeConfigs();

        $commands[] = 'cd '.$service->workdir();
        $commands[] = "echo 'Saved configuration files to {$service->workdir()}.'";
        $commands[] = 'docker compose pull';

        $server = data_get($service, 'server');

        if (! $server) {
            return;
        }

        instant_remote_process($commands, $service->server);
    }
}
