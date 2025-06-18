<?php

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Events\ServiceStatusChanged;
use App\Models\Server;
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

            $containersToStop = [];
            $applications = $service->applications()->get();
            foreach ($applications as $application) {
                $containersToStop[] = "{$application->name}-{$service->uuid}";
            }
            $dbs = $service->databases()->get();
            foreach ($dbs as $db) {
                $containersToStop[] = "{$db->name}-{$service->uuid}";
            }

            if (! empty($containersToStop)) {
                $this->stopContainersInParallel($containersToStop, $server);
            }

            if ($isDeleteOperation) {
                $service->deleteConnectedNetworks();
            }
            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, true);
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        } finally {
            ServiceStatusChanged::dispatch($service->environment->project->team->id);
        }
    }

    private function stopContainersInParallel(array $containersToStop, Server $server): void
    {
        $timeout = count($containersToStop) > 5 ? 10 : 30;
        $commands = [];
        $containerList = implode(' ', $containersToStop);
        $commands[] = "docker stop --time=$timeout $containerList";
        $commands[] = "docker rm -f $containerList";
        instant_remote_process(
            command: $commands,
            server: $server,
            throwError: false
        );
    }
}
