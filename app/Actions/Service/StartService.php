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
        $service->parse();
        if ($stopBeforeStart) {
            StopService::run(service: $service, dockerCleanup: false);
        }
        $service->saveComposeConfigs();
        $service->isConfigurationChanged(save: true);
        $workdir = $service->workdir();
        // $commands[] = "cd {$workdir}";
        $commands[] = "echo 'Saved configuration files to {$workdir}.'";
        // Ensure .env exists in the correct directory before docker compose tries to load it
        // This is defensive programming - saveComposeConfigs() already creates it,
        // but we guarantee it here in case of any edge cases or manual deployments
        $commands[] = "touch {$workdir}/.env";

        // Apply ownership settings to bind mount volumes before starting
        $this->applyVolumeOwnershipOnHost($service, $commands);

        if ($pullLatestImages) {
            $commands[] = "echo 'Pulling images.'";
            $commands[] = "docker compose --project-directory {$workdir} pull";
        }
        if ($service->networks()->count() > 0) {
            $commands[] = "echo 'Creating Docker network.'";
            $commands[] = "docker network inspect $service->uuid >/dev/null 2>&1 || docker network create --attachable $service->uuid";
        }
        $commands[] = 'echo Starting service.';
        $commands[] = "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$service->uuid} up -d --remove-orphans --force-recreate --build";
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

    /**
     * Apply ownership (chown/chmod) settings to bind mount volumes on the host server.
     * This collects all persistent storages from service applications and databases.
     */
    private function applyVolumeOwnershipOnHost(Service $service, array &$commands): void
    {
        // Collect all resources that can have persistent storages
        $resources = collect();

        // Add service applications
        foreach ($service->applications as $app) {
            $resources->push($app);
        }

        // Add service databases
        foreach ($service->databases as $db) {
            $resources->push($db);
        }

        foreach ($resources as $resource) {
            $storages = $resource->persistentStorages()
                ->whereNotNull('host_path')
                ->where('apply_ownership', true)
                ->where(function ($query) {
                    $query->whereNotNull('chown')->orWhereNotNull('chmod');
                })
                ->get();

            foreach ($storages as $storage) {
                $escapedPath = escapeshellarg($storage->host_path);
                $commands[] = "mkdir -p {$escapedPath}";

                if ($storage->chown) {
                    $recursiveFlag = $storage->recursive ? '-R ' : '';
                    $escapedChown = escapeshellarg($storage->chown);
                    $commands[] = "chown {$recursiveFlag}{$escapedChown} {$escapedPath} || echo 'Warning: Failed to set ownership for {$storage->host_path}'";
                }

                if ($storage->chmod) {
                    $recursiveFlag = $storage->recursive ? '-R ' : '';
                    $escapedChmod = escapeshellarg($storage->chmod);
                    $commands[] = "chmod {$recursiveFlag}{$escapedChmod} {$escapedPath} || echo 'Warning: Failed to set permissions for {$storage->host_path}'";
                }
            }
        }
    }
}
