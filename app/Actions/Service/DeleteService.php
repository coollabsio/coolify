<?php

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteService
{
    use AsAction;

    public function handle(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations, bool $dockerCleanup)
    {
        try {
            $server = data_get($service, 'server');
            if ($deleteVolumes && $server->isFunctional()) {
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
                foreach ($storagesToDelete as $storage) {
                    $commands[] = "docker volume rm -f $storage->name";
                }

                // Execute volume deletion first, this must be done first otherwise volumes will not be deleted.
                if (! empty($commands)) {
                    foreach ($commands as $command) {
                        $result = instant_remote_process([$command], $server, false);
                        if ($result !== null && $result !== 0) {
                            Log::error('Error deleting volumes: '.$result);
                        }
                    }
                }
            }

            if ($deleteConnectedNetworks) {
                $service->deleteConnectedNetworks();
            }

            instant_remote_process(["docker rm -f $service->uuid"], $server, throwError: false);
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage());
        } finally {
            if ($deleteConfigurations) {
                $service->deleteConfigurations();
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

            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, false, false);
            }
        }
    }
}
