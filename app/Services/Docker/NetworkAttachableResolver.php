<?php

namespace App\Services\Docker;

use App\Models\Application;
use App\Models\NetworkAttachment;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class NetworkAttachableResolver
{
    public function resolveResourceType(Model $resource): string
    {
        return match (true) {
            $resource instanceof Application => 'application',
            $resource instanceof Service => 'service',
            default => 'unknown',
        };
    }

    public function resolveServer(Model $resource): ?Server
    {
        if ($resource instanceof Service) {
            if (! $resource->exists && ! $resource->relationLoaded('server')) {
                return null;
            }

            return $resource->server;
        }

        if ($resource instanceof Application) {
            if (! $resource->exists && ! $resource->relationLoaded('destination')) {
                return null;
            }

            return $resource->destination?->server;
        }

        return null;
    }

    public function resolveRuntimeContainer(Model $resource, ?NetworkAttachment $attachment = null): ?array
    {
        $server = $this->resolveServer($resource);

        if (! $server) {
            return null;
        }

        $containers = $server->loadAllContainers();

        if (! $containers instanceof Collection) {
            $containers = collect($containers);
        }

        if ($attachment && filled($attachment->container_id)) {
            $cachedById = $containers
                ->first(fn (array $container): bool => (string) data_get($container, 'ID', data_get($container, 'Id')) === (string) $attachment->container_id);

            if (is_array($cachedById)) {
                return $this->runtimeContainerPayload($cachedById, $server);
            }
        }

        $matchedContainers = $containers
            ->map(fn (array $container): ?array => $this->resourceContainerPayload($resource, $container, $server))
            ->filter()
            ->values();

        if ($attachment && filled($attachment->container_name)) {
            $cachedByName = $matchedContainers
                ->first(fn (array $container): bool => $container['name'] === $attachment->container_name);

            if (is_array($cachedByName)) {
                return $cachedByName;
            }
        }

        $candidateNames = $this->candidateContainerNames($resource, $attachment);

        foreach ($candidateNames as $candidateName) {
            $candidate = $matchedContainers
                ->first(fn (array $container): bool => $container['name'] === $candidateName);

            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return $matchedContainers->first();
    }

    private function resourceContainerPayload(Model $resource, array $container, Server $server): ?array
    {
        $labels = $this->labels($container);

        if ($resource instanceof Application) {
            $applicationId = data_get($labels, 'coolify.applicationId');
            $pullRequestId = data_get($labels, 'coolify.pullRequestId');

            if ((string) $applicationId !== (string) $resource->id || filled($pullRequestId)) {
                return null;
            }

            return $this->runtimeContainerPayload($container, $server, $labels);
        }

        if ($resource instanceof Service) {
            $serviceId = data_get($labels, 'coolify.serviceId');

            if ((string) $serviceId !== (string) $resource->id) {
                return null;
            }

            return $this->runtimeContainerPayload($container, $server, $labels);
        }

        return null;
    }

    private function labels(array $container): Collection
    {
        $labels = data_get($container, 'Labels')
            ?? data_get($container, 'Config.Labels')
            ?? data_get($container, 'Spec.Labels')
            ?? [];

        return collect(Arr::undot(format_docker_labels_to_json($labels)->all()));
    }

    private function runtimeContainerPayload(array $container, Server $server, ?Collection $labels = null): ?array
    {
        $labels ??= $this->labels($container);
        $name = data_get($container, 'Names')
            ?? data_get($container, 'Name')
            ?? data_get($labels, 'com.docker.compose.service');

        if (! $name && $server->isSwarm()) {
            $name = data_get($labels, 'coolify.serviceName')
                ?? data_get($labels, 'coolify.name')
                ?? data_get($labels, 'com.docker.stack.namespace');
        }

        if (! filled($name)) {
            return null;
        }

        return [
            'id' => data_get($container, 'ID', data_get($container, 'Id')),
            'name' => ltrim((string) $name, '/'),
        ];
    }

    private function candidateContainerNames(Model $resource, ?NetworkAttachment $attachment = null): array
    {
        $candidateNames = collect();

        if ($attachment && filled($attachment->container_name)) {
            $candidateNames->push($attachment->container_name);
        }

        if ($resource instanceof Application) {
            if (filled($resource->settings?->custom_internal_name)) {
                $candidateNames->push($resource->settings->custom_internal_name);
            }

            if ((bool) $resource->settings?->is_consistent_container_name_enabled && filled($resource->uuid)) {
                $candidateNames->push($resource->uuid);
            }
        }

        if ($resource instanceof Service && filled($resource->uuid)) {
            $candidateNames->push($resource->uuid);
        }

        return $candidateNames
            ->filter()
            ->map(fn (string $name): string => ltrim($name, '/'))
            ->unique()
            ->values()
            ->all();
    }
}
