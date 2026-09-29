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

        $this->removeContainers($service, $resource);
    }

    private function removeContainers(Service $service, ServiceApplication|ServiceDatabase|null $subresource = null): void
    {
        $filters = [];
        $legacyFilters = [];
        if ($subresource !== null) {
            $subType = $subresource instanceof ServiceDatabase ? 'database' : 'application';
            $filters = ["label=coolify.service.subUuid={$subresource->uuid}", "label=coolify.service.subType={$subType}"];
            // Containers from before the UUID labels: the compose service key is the subresource name.
            $legacyFilters = ["label=com.docker.compose.service={$subresource->name}", "label=coolify.service.subType={$subType}"];
        }

        // One sh -c line, so non-root servers run the whole script with sudo. A leading variable
        // assignment would become "sudo container_ids=...", which sudo rejects.
        $script = containerIdsByOwnerScript('service', $service->uuid, $filters, legacyExtraFilters: $legacyFilters)
            .'; [ -z "$container_ids" ] || docker rm -f $container_ids';
        instant_remote_process(['sh -c '.escapeshellarg($script)], $service->server);
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
