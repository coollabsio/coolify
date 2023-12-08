<?php

namespace App\Actions\Service;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Service;

class DeleteService
{
    use AsAction;
    public function handle(Service $service)
    {
        StopService::run($service);
        $server = data_get($service, 'server');
        $storagesToDelete = collect([]);

        $service->environment_variables()->delete();
        $commands = [];
        foreach ($service->applications()->get() as $application) {
            $storages = $application->persistentStorages()->get();
            foreach ($storages as $storage) {
                $storagesToDelete->push($storage);
            }
            $application->delete();
        }
        foreach ($service->databases()->get() as $database) {
            $storages = $database->persistentStorages()->get();
            foreach ($storages as $storage) {
                $storagesToDelete->push($storage);
            }
            $database->delete();
        }
        foreach ($storagesToDelete as $storage) {
            $commands[] = "docker volume rm -f $storage->name";
        }
        $commands[] = "docker rm -f $service->uuid";

        instant_remote_process($commands, $server, false);

        $service->forceDelete();
    }
}
