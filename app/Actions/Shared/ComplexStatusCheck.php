<?php

namespace App\Actions\Shared;

use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class ComplexStatusCheck
{
    use AsAction;

    public function handle(Application $application)
    {
        $servers = $application->additional_servers;
        $servers->push($application->destination->server);
        foreach ($servers as $server) {
            $is_main_server = $application->destination->server->id === $server->id;
            if (! $server->isFunctional()) {
                if ($is_main_server) {
                    $application->update(['status' => 'exited:unhealthy']);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited:unhealthy']);

                    continue;
                }
            }
            $containers = instant_remote_process(["docker container inspect $(docker container ls -q --filter 'label=coolify.applicationId={$application->id}' --filter 'label=coolify.pullRequestId=0') --format '{{json .}}'"], $server, false);
            $containers = format_docker_command_output_to_json($containers);

            if ($containers->count() > 0) {
                $statusToSet = $this->aggregateContainerStatuses($application, $containers);

                if ($is_main_server) {
                    $statusFromDb = $application->status;
                    if ($statusFromDb !== $statusToSet) {
                        $application->update(['status' => $statusToSet]);
                    }
                } else {
                    $additional_server = $application->additional_servers()->wherePivot('server_id', $server->id);
                    $statusFromDb = $additional_server->first()->pivot->status;
                    if ($statusFromDb !== $statusToSet) {
                        $additional_server->updateExistingPivot($server->id, ['status' => $statusToSet]);
                    }
                }
            } else {
                if ($is_main_server) {
                    $application->update(['status' => 'exited:unhealthy']);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited:unhealthy']);

                    continue;
                }
            }
        }
    }

    private function aggregateContainerStatuses($application, $containers)
    {
        $dockerComposeRaw = data_get($application, 'docker_compose_raw');
        $excludedContainers = collect();

        if ($dockerComposeRaw) {
            try {
                $dockerCompose = \Symfony\Component\Yaml\Yaml::parse($dockerComposeRaw);
                $services = data_get($dockerCompose, 'services', []);

                foreach ($services as $serviceName => $serviceConfig) {
                    $excludeFromHc = data_get($serviceConfig, 'exclude_from_hc', false);
                    $restartPolicy = data_get($serviceConfig, 'restart', 'always');

                    if ($excludeFromHc || $restartPolicy === 'no') {
                        $excludedContainers->push($serviceName);
                    }
                }
            } catch (\Exception $e) {
                // If we can't parse, treat all containers as included
            }
        }

        $hasRunning = false;
        $hasRestarting = false;
        $hasUnhealthy = false;
        $hasExited = false;
        $relevantContainerCount = 0;

        foreach ($containers as $container) {
            $labels = data_get($container, 'Config.Labels', []);
            $serviceName = data_get($labels, 'com.docker.compose.service');

            if ($serviceName && $excludedContainers->contains($serviceName)) {
                continue;
            }

            $relevantContainerCount++;
            $containerStatus = data_get($container, 'State.Status');
            $containerHealth = data_get($container, 'State.Health.Status', 'unhealthy');

            if ($containerStatus === 'restarting') {
                $hasRestarting = true;
                $hasUnhealthy = true;
            } elseif ($containerStatus === 'running') {
                $hasRunning = true;
                if ($containerHealth === 'unhealthy') {
                    $hasUnhealthy = true;
                }
            } elseif ($containerStatus === 'exited') {
                $hasExited = true;
                $hasUnhealthy = true;
            }
        }

        if ($relevantContainerCount === 0) {
            return 'running:healthy';
        }

        if ($hasRestarting) {
            return 'degraded:unhealthy';
        }

        if ($hasRunning && $hasExited) {
            return 'degraded:unhealthy';
        }

        if ($hasRunning) {
            return $hasUnhealthy ? 'running:unhealthy' : 'running:healthy';
        }

        return 'exited:unhealthy';
    }
}
