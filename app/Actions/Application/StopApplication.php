<?php

namespace App\Actions\Application;

use App\Actions\Server\CleanupDocker;
use App\Events\ServiceStatusChanged;
use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplication
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Application $application, bool $previewDeployments = false, bool $dockerCleanup = true, bool $resetRestartCount = true, bool $removeContainers = true): ?string
    {
        $containerPresent = ! $removeContainers;
        $servers = collect([$application->destination->server]);
        if ($application?->additional_servers?->count() > 0) {
            $servers = $servers->merge($application->additional_servers);
        }
        foreach ($servers as $server) {
            try {
                if (! $server->isFunctional()) {
                    return 'Server is not functional';
                }

                if ($server->isSwarm()) {
                    $containerPresent = false;
                    instant_remote_process(["docker stack rm {$application->uuid}"], $server);

                    continue;
                }

                $containers = $previewDeployments
                    ? getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true)
                    : getCurrentApplicationContainerStatus($server, $application->id, 0);

                $containersToStop = $containers->pluck('Names')->toArray();
                $timeout = $application->settings->stopGracePeriodSeconds();

                foreach ($containersToStop as $containerName) {
                    $commands = [dockerStopCommand($timeout, $containerName, $server)];
                    if ($removeContainers) {
                        $commands[] = "docker rm -f $containerName";
                    } else {
                        array_unshift($commands, "docker update --restart=no $containerName");
                    }

                    instant_remote_process(command: $commands, server: $server, throwError: false);
                }

                if ($removeContainers && $application->build_pack === 'dockercompose') {
                    $application->deleteConnectedNetworks();
                }

                if ($dockerCleanup) {
                    CleanupDocker::dispatch($server, false, false);
                }
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        $status = [
            'status' => 'exited',
            'container_present' => $containerPresent,
        ];
        if ($resetRestartCount) {
            $status = array_merge($status, [
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
                'restart_limit_reached' => false,
            ]);
        }
        $application->update($status);

        ServiceStatusChanged::dispatch($application->environment->project->team->id);
    }
}
