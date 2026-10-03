<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeleteService
{
    public function cleanupRemote(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations): void
    {
        $server = data_get($service, 'server');
        if (! $server?->isFunctional()) {
            throw new RuntimeException('Server is not functional.');
        }

        $this->removeContainers($service);

        $failedVolumes = $deleteVolumes ? $this->removeVolumes($service) : [];

        if ($deleteConnectedNetworks) {
            $service->deleteConnectedNetworks();
        }
        // The configuration directory holds the .env file with plaintext secrets, so it is removed
        // also when a volume could not be removed.
        if ($deleteConfigurations) {
            $service->deleteConfigurations();
        }

        if ($failedVolumes !== []) {
            Log::warning('Could not remove all volumes of a deleted service.', [
                'service_uuid' => $service->uuid,
                'server_uuid' => $server->uuid,
                'volumes' => $failedVolumes,
            ]);

            throw new RuntimeException('Could not remove these volumes: '.collect($failedVolumes)
                ->map(fn (string $error, string $volume): string => "{$volume} ({$error})")
                ->implode(', '));
        }
    }

    /**
     * Removes every volume of the service. A volume that cannot be removed (for example because
     * another container still uses it) does not stop the removal of the others.
     *
     * @return array<string, string> The error message of each volume that could not be removed.
     */
    private function removeVolumes(Service $service): array
    {
        $volumeNames = [];
        foreach ($service->applications()->get() as $application) {
            foreach ($application->persistentStorages()->get() as $storage) {
                $volumeNames[] = $storage->name;
            }
        }
        foreach ($service->databases()->get() as $database) {
            foreach ($database->persistentStorages()->get() as $storage) {
                $volumeNames[] = $storage->name;
            }
        }

        $failedVolumes = [];
        foreach ($volumeNames as $volumeName) {
            try {
                instant_remote_process(['docker volume rm -f '.escapeshellarg($volumeName)], $service->server);
            } catch (Throwable $e) {
                $failedVolumes[$volumeName] = $e->getMessage();
            }
        }

        return $failedVolumes;
    }

    /**
     * Removes the container of one service part. Returns false when the server does not respond,
     * also when it is still marked reachable but the SSH call fails: the container then stays until
     * the service starts again (compose up --remove-orphans), and the part can still be deleted from Coolify.
     */
    public function removeSubresourceContainer(ServiceApplication|ServiceDatabase $resource): bool
    {
        $service = $resource->service;
        if (! $service?->server?->isFunctional()) {
            return false;
        }

        try {
            $this->removeContainers($service, $resource);
        } catch (Throwable $e) {
            Log::warning('Could not remove the container of a service part; it is removed when the service starts again.', [
                'service_uuid' => $service->uuid,
                'subresource_uuid' => $resource->uuid,
                'server_uuid' => $service->server->uuid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
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
