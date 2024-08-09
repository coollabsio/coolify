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
            $applications = $service->applications()->get();
            foreach ($applications as $application) {
                $this->stopContainer("{$application->name}-{$service->uuid}", $server, 600);
                $application->update(['status' => 'exited']);
            }
            $dbs = $service->databases()->get();
            foreach ($dbs as $db) {
                $this->stopContainer("{$db->name}-{$service->uuid}", $server, 600);
                $db->update(['status' => 'exited']);
            }

            if (!$isDeleteOperation) {
                // Only run if not a deletion operation as for deletion we can specify if we want to delete networks or not
                $service->delete_connected_networks($service->uuid);
                CleanupDocker::run($server, true);
            }
        } catch (\Exception $e) {
            ray($e->getMessage());
            return $e->getMessage();
        }
    }

    private function stopContainer(string $containerName, $server, int $timeout = 600)
    {
        try {
            instant_remote_process(command: ["docker stop --time=$timeout $containerName"], server: $server, throwError: false);
            $isRunning = instant_remote_process(command: ["docker inspect -f '{{.State.Running}}' $containerName"], server: $server, throwError: false);

            if (trim($isRunning) === 'true') {
                instant_remote_process(command: ["docker kill $containerName"], server: $server, throwError: false);
            }
        } catch (\Exception $error) {
        }

        instant_remote_process(command: ["docker rm -f $containerName"], server: $server, throwError: false);
    }
}
