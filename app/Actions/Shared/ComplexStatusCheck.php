<?php

namespace App\Actions\Shared;

use App\Models\Application;
use App\Services\ContainerStatusAggregator;
use App\Traits\CalculatesExcludedStatus;
use App\Actions\Shared\DockerInspectCache;
use Lorisleiva\Actions\Concerns\AsAction;

class ComplexStatusCheck
{
    use AsAction;
    use CalculatesExcludedStatus;

    public function handle(Application $application, DockerInspectCache $dockerInspectCache = new DockerInspectCache())
    {
        $servers = $application->additional_servers;
        $servers->push($application->destination->server);

        $serversToInspect = $servers->filter(fn($server) => !isset($dockerInspectCache->data[$server->id]));
        
        if ($serversToInspect->isNotEmpty()) {
            $results = instant_remote_process(["docker container inspect $(docker container ls -aq) --format '{{json .}}'"], $serversToInspect, false);

            foreach ($results as $serverId => $result) {
                $dockerInspectCache->data[$serverId] = format_docker_command_output_to_json($result);
            }
        }

        foreach ($servers as $server) {
            $is_main_server = $application->destination->server->id === $server->id;
            if (! $server->isFunctional()) {
                if ($is_main_server) {
                    $application->update(['status' => 'exited']);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited']);

                    continue;
                }
            }
            $allContainers = $dockerInspectCache->data[$server->id];

            $containers = collect($allContainers)->filter(function ($container) use ($application) {
                $labels = data_get($container, 'Config.Labels', []);
                $appId = $labels['coolify.applicationId'] ?? null;
                $pullRequestId = $labels['coolify.pullRequestId'] ?? null;

                return $appId !== null && intval($appId) === $application->id && $pullRequestId !== null && intval($pullRequestId) === 0;
            });

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
                    $application->update(['status' => 'exited']);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited']);

                    continue;
                }
            }
        }
    }

    private function aggregateContainerStatuses($application, $containers)
    {
        $dockerComposeRaw = data_get($application, 'docker_compose_raw');
        $excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);

        // Filter non-excluded containers
        $relevantContainers = collect($containers)->filter(function ($container) use ($excludedContainers) {
            $labels = data_get($container, 'Config.Labels', []);
            $serviceName = data_get($labels, 'com.docker.compose.service');

            return ! ($serviceName && $excludedContainers->contains($serviceName));
        });

        // If all containers are excluded, calculate status from excluded containers
        // but mark it with :excluded to indicate monitoring is disabled
        if ($relevantContainers->isEmpty()) {
            return $this->calculateExcludedStatus($containers, $excludedContainers);
        }

        // Use ContainerStatusAggregator service for state machine logic
        $aggregator = new ContainerStatusAggregator;

        return $aggregator->aggregateFromContainers($relevantContainers);
    }
}
