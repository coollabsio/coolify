<?php

namespace App\Actions\Service;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;

class StopService
{
    use AsAction;

    public function handle(Service $service)
    {
        try {
            $server = $service->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }
            ray('Stopping service: '.$service->name);
            $applications = $service->applications()->get();
            foreach ($applications as $application) {
                instant_remote_process(["docker rm -f {$application->name}-{$service->uuid}"], $service->server);
                $application->update(['status' => 'exited']);
            }
            $dbs = $service->databases()->get();
            foreach ($dbs as $db) {
                instant_remote_process(["docker rm -f {$db->name}-{$service->uuid}"], $service->server);
                $db->update(['status' => 'exited']);
            }
            instant_remote_process(["docker network disconnect {$service->uuid} coolify-proxy 2>/dev/null"], $service->server, false);
            instant_remote_process(["docker network rm {$service->uuid} 2>/dev/null"], $service->server, false);
            // TODO: make notification for databases
            // $service->environment->project->team->notify(new StatusChanged($service));
        } catch (\Exception $e) {
            echo $e->getMessage();
            ray($e->getMessage());

            return $e->getMessage();
        }

    }
}
