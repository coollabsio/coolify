<?php

namespace App\Actions\Service;

use App\Models\Service;

class DeleteService
{
    public function cleanupRemote(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations): void
    {
        $server = data_get($service, 'server');
        if ($deleteVolumes && $server->isFunctional()) {
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
                instant_remote_process([$command], $server, false);
            }
        }

        if ($deleteConnectedNetworks) {
            $service->deleteConnectedNetworks();
        }
        if ($deleteConfigurations) {
            $service->deleteConfigurations();
        }
        instant_remote_process(["docker rm -f $service->uuid"], $server, throwError: false);
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
