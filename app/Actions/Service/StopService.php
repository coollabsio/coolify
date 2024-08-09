<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Actions\Server\CleanupDocker;
use Lorisleiva\Actions\Concerns\AsAction;
use Illuminate\Support\Facades\Process;
use Illuminate\Process\InvokedProcess;

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

            $containersToStop = $this->getContainersToStop($service);

            $this->stopContainers($containersToStop, $server);

            if (!$isDeleteOperation) {
                $service->delete_connected_networks($service->uuid);
                CleanupDocker::run($server, true);
            }
        } catch (\Exception $e) {
            ray($e->getMessage());
            return $e->getMessage();
        }
    }

    private function getContainersToStop(Service $service): array
    {
        $containersToStop = [];
        $applications = $service->applications()->get();
        foreach ($applications as $application) {
            $containersToStop[] = "{$application->name}-{$service->uuid}";
        }
        $dbs = $service->databases()->get();
        foreach ($dbs as $db) {
            $containersToStop[] = "{$db->name}-{$service->uuid}";
        }
        return $containersToStop;
    }

    private function stopContainers(array $containerNames, $server, int $timeout = 300)
    {
        $processes = [];
        foreach ($containerNames as $containerName) {
            $processes[$containerName] = $this->stopContainer($containerName, $server, $timeout);
        }

        $startTime = time();
        while (count($processes) > 0) {
            $finishedProcesses = array_filter($processes, function ($process) {
                return !$process->running();
            });
            foreach ($finishedProcesses as $containerName => $process) {
                unset($processes[$containerName]);
                $this->removeContainer($containerName, $server);
            }

            if (time() - $startTime >= $timeout) {
                $this->forceStopRemainingContainers(array_keys($processes), $server);
                break;
            }

            usleep(100000);
        }
    }

    private function stopContainer(string $containerName, $server, int $timeout): InvokedProcess
    {
        return Process::timeout($timeout)->start("docker stop --time=$timeout $containerName");
    }

    private function removeContainer(string $containerName, $server)
    {
        instant_remote_process(command: ["docker rm -f $containerName"], server: $server, throwError: false);
    }

    private function forceStopRemainingContainers(array $containerNames, $server)
    {
        foreach ($containerNames as $containerName) {
            instant_remote_process(command: ["docker kill $containerName"], server: $server, throwError: false);
            $this->removeContainer($containerName, $server);
        }
    }
}
