<?php

namespace App\Actions\Shared;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;

class PullImage
{
    use AsAction;

    public function handle(Service $resource)
    {
        $resource->saveComposeConfigs();

        $commands[] = 'cd '.$resource->workdir();
        $commands[] = "echo 'Saved configuration files to {$resource->workdir()}.'";
        $commands[] = 'docker compose pull';

        $server = data_get($resource, 'server');

        if (! $server) {
            return;
        }

        instant_remote_process($commands, $resource->server);
    }
}
