<?php

namespace App\Actions\Service;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Service;

class StopService
{
    use AsAction;
    public function handle(Service $service)
    {
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
        instant_remote_process(["docker network disconnect {$service->uuid} coolify-proxy 2>/dev/null"], $service->server);
    }
}
