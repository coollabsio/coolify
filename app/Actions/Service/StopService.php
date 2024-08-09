<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Actions\Server\CleanupDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class StopService
{
    use AsAction;

    public function handle(Service $service, bool $isDeleteOperation = false)
    {
        try {
            $server = $service->destination->server;
            if (!$server->isFunctional()) {
                return 'Server is not functional';
            }
            ray('Stopping service: ' . $service->name);

            $containersToStop = $service->getContainersToStop();
            $service->stopContainers($containersToStop, $server);

            if (!$isDeleteOperation) {
                $service->delete_connected_networks($service->uuid);
                CleanupDocker::run($server, true);
            }
        } catch (\Exception $e) {
            ray($e->getMessage());
            return $e->getMessage();
        }
    }
}