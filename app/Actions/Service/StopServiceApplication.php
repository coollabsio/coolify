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

        if ($removeContainer) {
            $commands = ["docker rm -f {$containerName}"];
        } else {
            $commands = [
                "docker update --restart=no {$containerName}",
                "docker stop {$containerName}",
            ];
        }
        instant_remote_process($commands, $server, throwError: ! $removeContainer);

        $serviceApplication->update(['status' => 'exited']);
        if ($resetRestartCount) {
            $serviceApplication->resetRestartLimit();
        }
        ServiceStatusChanged::dispatch($service->environment->project->team->id);
    }
}
