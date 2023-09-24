<?php

namespace App\Actions\Service;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Service;

class StartService
{
    use AsAction;
    public function handle(Service $service)
    {
        $workdir = service_configuration_dir() . "/{$service->uuid}";
        $commands[] = "echo '####### Starting service {$service->name} on {$service->server->name}.'";
        $commands[] = "echo '####### Pulling images.'";
        $commands[] = "mkdir -p $workdir";
        $commands[] = "cd $workdir";

        $docker_compose_base64 = base64_encode($service->docker_compose);
        $commands[] = "echo $docker_compose_base64 | base64 -d > docker-compose.yml";
        $envs = $service->environment_variables()->get();
        $commands[] = "rm -f .env || true";
        foreach ($envs as $env) {
            $commands[] = "echo '{$env->key}={$env->value}' >> .env";
        }
        $commands[] = "docker compose pull --quiet";
        $commands[] = "echo '####### Starting containers.'";
        $commands[] = "docker compose up -d >/dev/null 2>&1";
        $commands[] = "docker network connect $service->uuid coolify-proxy 2>/dev/null || true";
        $activity = remote_process($commands, $service->server);
        return $activity;
    }
}
