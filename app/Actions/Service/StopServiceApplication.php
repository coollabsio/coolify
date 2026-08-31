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

    public function handle(ServiceApplication|ServiceDatabase $serviceApplication, bool $resetRestartCount = true, bool $removeContainer = false): void
    {
        $service = $serviceApplication->service;
        $server = $service->destination->server;
        $containerName = escapeshellarg($serviceApplication->name.'-'.$service->uuid);

        $commands = ["docker stop {$containerName}"];
        if ($removeContainer) {
            $commands[] = "docker rm -f {$containerName}";
        } else {
            array_unshift($commands, "docker update --restart=no {$containerName}");
        }
        instant_remote_process($commands, $server);

        $serviceApplication->update(['status' => 'exited']);
        if ($resetRestartCount) {
            $serviceApplication->resetRestartLimit();
        }
        ServiceStatusChanged::dispatch($service->environment->project->team->id);
    }
}
