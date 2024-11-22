<?php

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;

class StopService
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Service $service, bool $isDeleteOperation = false, bool $dockerCleanup = true)
    {
        try {
            $server = $service->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $containersToStop = $service->getContainersToStop();
            $service->stopContainers($containersToStop, $server);

            if (! $isDeleteOperation) {
                $service->delete_connected_networks($service->uuid);
                if ($dockerCleanup) {
                    CleanupDocker::dispatch($server, true);
                }
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
