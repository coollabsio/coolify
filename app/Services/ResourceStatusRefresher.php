<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;

/**
 * Stores the container status of one resource right after it started.
 *
 * The full server check (GetContainersStatus) runs on a shared queue and can wait behind other
 * jobs, so the UI can show "exited" for a resource that already runs. This class inspects only the
 * containers of the started resource and writes the same status strings as the full check. It
 * does not track restarts or touch other resources; the full check still owns that.
 */
class ResourceStatusRefresher
{
    /**
     * Inspections while a health check still reports "starting" (about 15 seconds in total).
     */
    public const HEALTH_WAIT_ATTEMPTS = 8;

    public const HEALTH_WAIT_INTERVAL_SECONDS = 2;

    public function refreshDatabase(Model $database): void
    {
        $server = $database->destination->server;
        $command = 'docker container inspect '.escapeshellarg($database->uuid).' --format \'{{json .}}\'';

        $this->refresh($server, $command, function (Collection $containers) use ($database): void {
            $container = $containers->first(fn ($container) => data_get($container, 'Name') === "/{$database->uuid}");
            if ($container) {
                $this->store($database, ContainerStatusAggregator::containerStatus($container));
            }
        });
    }

    public function refreshService(Service $service): void
    {
        $script = containerIdsByOwnerScript('service', $service->uuid)
            .'; [ -z "$container_ids" ] || docker container inspect $container_ids --format \'{{json .}}\'';

        $this->refresh($service->server, 'sh -c '.escapeshellarg($script), function (Collection $containers) use ($service): void {
            $services = collect([$service]);
            $parts = [];
            foreach ($containers as $container) {
                $labels = format_docker_labels_to_json(data_get($container, 'Config.Labels') ?? []);
                $containerName = $labels->get('com.docker.compose.service');
                if (! $containerName || filter_var($labels->get('com.docker.compose.oneoff'), FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                [, $subType, $part] = resolveServiceContainerOwner($services, $labels);
                if (! $part) {
                    continue;
                }
                $key = $subType.':'.$part->id;
                $parts[$key] ??= ['part' => $part, 'statuses' => collect()];
                $parts[$key]['statuses']->put($containerName, ContainerStatusAggregator::containerStatus($container));
            }

            $aggregator = new ContainerStatusAggregator;
            foreach ($parts as ['part' => $part, 'statuses' => $statuses]) {
                $this->store($part, $aggregator->aggregateForCompose($statuses, $service->docker_compose_raw));
            }
        });
    }

    /**
     * Inspect the containers and store their status. While a health check reports "starting",
     * store that status (so the UI shows it) and inspect again a few times.
     *
     * @param  callable(Collection): void  $storeStatuses
     */
    private function refresh(Server $server, string $command, callable $storeStatuses): void
    {
        if (! $server->isFunctional() || $server->isSwarm()) {
            return;
        }

        for ($attempt = 1; ; $attempt++) {
            $containers = format_docker_command_output_to_json(instant_remote_process([$command], $server, false) ?? '')->filter();
            if ($containers->isEmpty()) {
                return;
            }

            $storeStatuses($containers);

            $healthStarting = $containers->contains(fn ($container) => data_get($container, 'State.Health.Status') === 'starting');
            if (! $healthStarting || $attempt >= self::HEALTH_WAIT_ATTEMPTS) {
                return;
            }

            Sleep::for(self::HEALTH_WAIT_INTERVAL_SECONDS)->seconds();
        }
    }

    private function store(Model $resource, string $status): void
    {
        if ($resource->status !== $status) {
            $resource->update(['status' => $status]);
        } else {
            $resource->update(['last_online_at' => now()]);
        }
    }
}
