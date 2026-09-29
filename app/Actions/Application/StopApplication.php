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
        $errors = [];
        foreach ($servers as $server) {
            try {
                if (! $server->isFunctional()) {
                    $errors[] = "Server {$server->name} is not functional.";

                    continue;
                }

                if ($server->isSwarm()) {
                    $containerPresent = false;
                    instant_remote_process(['docker stack rm '.escapeshellarg($application->uuid)], $server);

                    continue;
                }

                $containers = $previewDeployments
                    ? getCurrentApplicationContainerStatus($server, $application, includePullrequests: true)
                    : getCurrentApplicationContainerStatus($server, $application, 0);

                $containersToStop = $containers->pluck('Names')->toArray();
                $timeout = $application->settings->stopGracePeriodSeconds();

                foreach ($containersToStop as $containerName) {
                    $escapedContainerName = escapeshellarg($containerName);
                    $commands = [dockerStopCommand($timeout, $escapedContainerName, $server)];
                    if ($removeContainers) {
                        $commands[] = "docker rm -f {$escapedContainerName}";
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
                $errors[] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            return implode(' ', $errors);
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

        return null;
    }
}
