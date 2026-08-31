<?php

namespace App\Actions\Application;

use App\Events\ServiceStatusChanged;
use App\Models\ApplicationPreview;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplicationPreview
{
    use AsAction;

    public function handle(ApplicationPreview $preview, bool $resetRestartCount = true, bool $removeContainer = true): void
    {
        $application = $preview->application;
        $server = $application->destination->server;
        $containers = getCurrentApplicationContainerStatus($server, $application->id, $preview->pull_request_id);

        foreach ($containers->pluck('Names') as $containerName) {
            $commands = [dockerStopCommand($application->settings->stopGracePeriodSeconds(), $containerName, $server)];
            if ($removeContainer) {
                $commands[] = "docker rm -f $containerName";
            } else {
                array_unshift($commands, "docker update --restart=no $containerName");
            }
            instant_remote_process($commands, $server, false);
        }

        $preview->update(['status' => 'exited']);
        if ($resetRestartCount) {
            $preview->resetRestartLimit();
        }

        ServiceStatusChanged::dispatch($application->environment->project->team->id);
    }
}
