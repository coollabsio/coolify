<?php

namespace App\Actions\Service;

use App\Events\ServiceStatusChanged;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Lorisleiva\Actions\Concerns\AsAction;

class StopServiceApplication
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(ServiceApplication|ServiceDatabase $serviceApplication): void
    {
        $service = $serviceApplication->service;
        $server = $service->destination->server;
        $containerName = escapeshellarg($serviceApplication->name.'-'.$service->uuid);

        instant_remote_process([
            "docker stop {$containerName}",
        ], $server);

        $serviceApplication->update(['status' => 'exited']);
        ServiceStatusChanged::dispatch($service->environment->project->team->id);
    }
}
