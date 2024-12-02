<?php

namespace App\Actions\Service;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartService
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Service $service)
    {
        $service->saveComposeConfigs();
        $commands[] = 'cd '.$service->workdir();
        $commands[] = "echo 'Saved configuration files to {$service->workdir()}.'";
        if ($service->networks()->count() > 0) {
            $commands[] = "echo 'Creating Docker network.'";
            $commands[] = "docker network inspect $service->uuid >/dev/null 2>&1 || docker network create --attachable $service->uuid";
        }
        $commands[] = 'echo Starting service.';
        $commands[] = "echo 'Pulling images.'";
        $commands[] = 'docker compose pull';
        $commands[] = "echo 'Starting containers.'";
        $commands[] = 'docker compose up -d --remove-orphans --force-recreate --build';
        $commands[] = "docker network connect $service->uuid coolify-proxy >/dev/null 2>&1 || true";
        if (data_get($service, 'connect_to_docker_network')) {
            $compose = data_get($service, 'docker_compose', []);
            $network = $service->destination->network;
            $serviceNames = data_get(Yaml::parse($compose), 'services', []);
            foreach ($serviceNames as $serviceName => $serviceConfig) {
                $commands[] = "docker network connect --alias {$serviceName}-{$service->uuid} $network {$serviceName}-{$service->uuid} >/dev/null 2>&1 || true";
            }
        }

        return remote_process($commands, $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStatusChanged');
    }
}
