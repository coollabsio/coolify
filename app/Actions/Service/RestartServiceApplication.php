<?php

namespace App\Actions\Service;

use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class RestartServiceApplication
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

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
