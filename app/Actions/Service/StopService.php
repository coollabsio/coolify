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
                if ($applications->count() < 6) {
                    instant_remote_process(command: ["docker stop --time=10 {$application->name}-{$service->uuid}"], server: $server, throwError: false);
                }
                instant_remote_process(command: ["docker rm {$application->name}-{$service->uuid}"], server: $server, throwError: false);
                instant_remote_process(command: ["docker rm -f {$application->name}-{$service->uuid}"], server: $server, throwError: false);
                $application->update(['status' => 'exited']);
            }
            $dbs = $service->databases()->get();
            foreach ($dbs as $db) {
                if ($dbs->count() < 6) {

                    instant_remote_process(command: ["docker stop --time=10 {$db->name}-{$service->uuid}"], server: $server, throwError: false);
                }
                instant_remote_process(command: ["docker rm {$db->name}-{$service->uuid}"], server: $server, throwError: false);
                instant_remote_process(command: ["docker rm -f {$db->name}-{$service->uuid}"], server: $server, throwError: false);
                $db->update(['status' => 'exited']);
            }
            instant_remote_process(["docker network disconnect {$service->uuid} coolify-proxy"], $service->server);
            instant_remote_process(["docker network rm {$service->uuid}"], $service->server);
        } catch (\Exception $e) {
            ray($e->getMessage());

            return $e->getMessage();
        }

    }
}
