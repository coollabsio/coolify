<?php

namespace App\Actions\Service;

use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Lorisleiva\Actions\Concerns\AsAction;

class RestartServiceApplication
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(ServiceApplication|ServiceDatabase $serviceApplication): void
    {
        $service = $serviceApplication->service;
        $server = $service->destination->server;
        $containerName = escapeshellarg($serviceApplication->name.'-'.$service->uuid);

        instant_remote_process([
            "docker restart {$containerName}",
        ], $server);
    }
}
