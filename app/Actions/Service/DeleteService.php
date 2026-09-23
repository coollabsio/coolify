<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use RuntimeException;

class DeleteService
{
    public function cleanupRemote(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations): void
    {
        $server = data_get($service, 'server');
        if (! $server?->isFunctional()) {
            throw new RuntimeException('Server is not functional.');
        }

        $this->removeContainers($service);

        if ($deleteVolumes) {
            $commands = [];
            foreach ($service->applications()->get() as $application) {
                foreach ($application->persistentStorages()->get() as $storage) {
                    $commands[] = 'docker volume rm -f '.escapeshellarg($storage->name);
                }
            }
            foreach ($service->databases()->get() as $database) {
                foreach ($database->persistentStorages()->get() as $storage) {
                    $commands[] = 'docker volume rm -f '.escapeshellarg($storage->name);
                }
            }
            foreach ($commands as $command) {
                instant_remote_process([$command], $server);
            }
        }

        if ($deleteConnectedNetworks) {
            $service->deleteConnectedNetworks();
        }
        if ($deleteConfigurations) {
            $service->deleteConfigurations();
        }
    }

    public function removeSubresourceContainer(ServiceApplication|ServiceDatabase $resource): void
    {
        $service = $resource->service;
        $server = $service?->server;
        if (! $server?->isFunctional()) {
            throw new RuntimeException('Server is not functional.');
        }

        $this->removeContainers($service, $resource->id);
    }

    private function removeContainers(Service $service, ?int $subresourceId = null): void
    {
        $filters = "--filter 'label=coolify.serviceId={$service->id}'";
        if ($subresourceId !== null) {
            $filters .= " --filter 'label=coolify.service.subId={$subresourceId}'";
        }

        $command = "container_ids=\$(docker ps -aq {$filters}); [ -z \"\$container_ids\" ] || docker rm -f \$container_ids";
        instant_remote_process([$command], $service->server);
    }

    public function deleteLocal(Service $service): void
    {
        foreach ($service->applications()->get() as $application) {
            $application->forceDelete();
        }
        foreach ($service->databases()->get() as $database) {
            $database->forceDelete();
        }
        foreach ($service->scheduled_tasks as $task) {
            $task->delete();
        }
        $service->environment_variables()->delete();
        $service->tags()->detach();
        $service->forceDelete();
    }
}
