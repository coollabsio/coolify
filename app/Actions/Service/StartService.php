<?php

namespace App\Actions\Service;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Service;

class StartService
{
    use AsAction;
    public function handle(Service $service)
    {
        $service->saveComposeConfigs();
        $commands[] = "cd " . $service->workdir();
        $commands[] = "echo '####### Starting service {$service->name} on {$service->server->name}.'";
        $commands[] = "echo '####### Pulling images.'";
        $commands[] = "docker compose pull";
        $commands[] = "echo '####### Starting containers.'";
        $commands[] = "docker compose up -d";
        $commands[] = "docker network connect $service->uuid coolify-proxy 2>/dev/null || true";
        $activity = remote_process($commands, $service->server);
        return $activity;
    }
}
