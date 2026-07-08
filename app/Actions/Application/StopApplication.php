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

    public function handle(Application $application, bool $previewDeployments = false, bool $dockerCleanup = true, bool $resetRestartCount = true)
    {
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
                    instant_remote_process(["docker stack rm {$application->uuid}"], $server);

                    return;
                }

                $containers = $previewDeployments
                    ? getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true)
                    : getCurrentApplicationContainerStatus($server, $application->id, 0);

                $containersToStop = $containers->pluck('Names')->toArray();
                $timeout = $application->settings->stopGracePeriodSeconds();

                foreach ($containersToStop as $containerName) {
                    if (! $resetRestartCount) {
                        $crashLog = instant_remote_process(["docker logs -n 500 -t $containerName"], $server, throwError: false);
                        if ($crashLog !== null) {
                            $snapshot = $application->last_crash_logs ?? [];
                            $snapshot[$containerName] = removeAnsiColors($crashLog);
                            $application->update([
                                'last_crash_logs' => $snapshot,
                                'last_crash_logs_captured_at' => now(),
                            ]);
                        }
                    }

                    instant_remote_process(command: [
                        "docker stop --time=$timeout $containerName",
                        "docker rm -f $containerName",
                    ], server: $server, throwError: false);
                }

                if ($application->build_pack === 'dockercompose') {
                    $application->deleteConnectedNetworks();
                }

                if ($dockerCleanup) {
                    CleanupDocker::dispatch($server, false, false);
                }
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        if ($resetRestartCount) {
            $application->update([
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
                'last_crash_logs' => null,
                'last_crash_logs_captured_at' => null,
            ]);
        } else {
            $application->update([
                'status' => 'exited',
            ]);
        }

        ServiceStatusChanged::dispatch($application->environment->project->team->id);
    }
}
