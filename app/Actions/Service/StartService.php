<?php

namespace App\Actions\Service;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartService
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Service $service, bool $pullLatestImages = false, bool $stopBeforeStart = false)
    {
        // Validate service UUID exists
        if (empty($service->uuid)) {
            throw new \Exception('Service UUID is missing. Cannot start service without UUID.');
        }
        
        $service->parse();
        if ($stopBeforeStart) {
            StopService::run(service: $service, dockerCleanup: false);
        }
        $service->saveComposeConfigs();
        $service->isConfigurationChanged(save: true);
        $commands[] = 'cd '.$service->workdir();
        $commands[] = "echo 'Saved configuration files to {$service->workdir()}.'";
        if ($pullLatestImages) {
            $commands[] = "echo 'Pulling images.'";
            $commands[] = 'docker compose pull';
        }
        if ($service->networks()->count() > 0) {
            $commands[] = "echo 'Creating Docker network.'";
            $commands[] = "docker network inspect $service->uuid >/dev/null 2>&1 || docker network create --attachable $service->uuid";
        }
        $commands[] = 'echo Starting service.';
        $commands[] = 'docker compose up -d --remove-orphans --force-recreate --build';
        $commands[] = "docker network connect $service->uuid coolify-proxy >/dev/null 2>&1 || true";
        if (data_get($service, 'connect_to_docker_network')) {
            // Use docker_compose_raw if available, otherwise fall back to docker_compose
            $compose = $service->docker_compose_raw ?: $service->docker_compose;
            
            // Validate compose content exists before parsing
            if (empty($compose)) {
                throw new \Exception('Docker Compose configuration is missing. Cannot connect services to network.');
            }
            
            try {
                $parsedCompose = Yaml::parse($compose);
                $serviceNames = data_get($parsedCompose, 'services', []);
                
                if (empty($serviceNames)) {
                    throw new \Exception('No services found in Docker Compose configuration.');
                }
                
                $network = $service->destination->network;
                
                foreach ($serviceNames as $serviceName => $serviceConfig) {
                    // Validate service name before using in shell command to prevent injection
                    try {
                        validateShellSafePath($serviceName, 'service name');
                    } catch (\Exception $e) {
                        throw new \Exception("Invalid service name '{$serviceName}': {$e->getMessage()}");
                    }
                    
                    // Wait for container to exist before connecting network
                    // This prevents race conditions where network connect runs before container starts
                    $containerName = "{$serviceName}-{$service->uuid}";
                    // Wait up to 15 seconds (30 attempts * 0.5s) for container to be created
                    $commands[] = "i=0; while [ \$i -lt 30 ]; do docker ps -a --format '{{.Names}}' | grep -q '^{$containerName}$' && break || sleep 0.5; i=\$((i+1)); done";
                    $commands[] = "docker network connect --alias {$serviceName}-{$service->uuid} $network {$containerName} >/dev/null 2>&1 || true";
                }
            } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
                throw new \Exception("Failed to parse Docker Compose configuration: {$e->getMessage()}");
            } catch (\Exception $e) {
                throw new \Exception("Error processing Docker Compose services: {$e->getMessage()}");
            }
        }

        return remote_process($commands, $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStatusChanged');
    }
}
