<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Actions\Server\CleanupDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteService
{
    use AsAction;

    public function handle(Service $service, bool $deleteConfigurations, bool $deleteVolumes, bool $deleteImages, bool $deleteConnectedNetworks)
    {
        try {
            $server = data_get($service, 'server');

            if ($server->isFunctional()) {
                $storagesToDelete = collect([]);

                $service->environment_variables()->delete();

                $commands = [];
                foreach ($service->applications()->get() as $application) {
                    $storages = $application->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($service->databases()->get() as $database) {
                    $storages = $database->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }

                // Delete volumes if the flag is set
                if ($deleteVolumes) {
                    foreach ($service->applications()->get() as $application) {
                        $persistentStorages = $application->persistentStorages()->get();
                        $application->delete_volumes($persistentStorages);
                    }
                }

                // Delete networks if the flag is set
                if ($deleteConnectedNetworks) {
                    $uuid = $service->uuid;
                    $service->delete_connected_networks($uuid);
                }

                // Command to remove the service itself
                $commands[] = "docker rm -f $service->uuid";

                // Execute all commands
                instant_remote_process($commands, $server, false);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        } finally {
            // Delete configurations if the flag is set
            if ($deleteConfigurations) {
                $service->delete_configurations();
            }
            foreach ($service->applications()->get() as $application) {
                $application->forceDelete();
            }
            foreach ($service->databases()->get() as $database) {
                $database->forceDelete();
            }
            foreach ($service->scheduled_tasks as $task) {
                $task->delete();
            }
            $service->tags()->detach();
            $service->forceDelete();

            // Run cleanup if images need to be deleted
            if ($deleteImages) {
                CleanupDocker::run($server, true);
            }
        }
    }
}
