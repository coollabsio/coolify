<?php

namespace App\Actions\Service;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteService
{
    use AsAction;

    public function handle(Service $service)
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
                foreach ($storagesToDelete as $storage) {
                    $commands[] = "docker volume rm -f $storage->name";
                }
                $commands[] = "docker rm -f $service->uuid";

                instant_remote_process($commands, $server, false);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        } finally {
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
        }
    }
}
