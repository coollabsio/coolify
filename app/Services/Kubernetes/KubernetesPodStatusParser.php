<?php

namespace App\Services\Kubernetes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use JsonException;

class KubernetesPodStatusParser
{
    /**
     * @return array<int, array{
     *     name: string,
     *     namespace: string,
     *     phase: string,
     *     ready: string,
     *     restarts: int,
     *     node: string,
     *     age: string,
     *     containers: string,
     *     container_names: array<int, string>,
     *     application_uuid: ?string
     * }>
     *
     * @throws JsonException
     */
    public function parse(string $json): array
    {
        $payload = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        return collect(data_get($payload, 'items', []))
            ->map(fn (array $pod) => $this->pod($pod))
            ->filter(fn (array $pod) => $pod['name'] !== '')
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    private function pod(array $pod): array
    {
        $containerStatuses = collect(data_get($pod, 'status.containerStatuses', []));
        $containerNames = $this->containerNames($pod, $containerStatuses);

        return [
            'name' => (string) data_get($pod, 'metadata.name', ''),
            'namespace' => (string) data_get($pod, 'metadata.namespace', ''),
            'phase' => (string) data_get($pod, 'status.phase', 'Unknown'),
            'ready' => $this->ready($containerStatuses, count($containerNames)),
            'restarts' => $containerStatuses->sum(fn (array $container) => (int) data_get($container, 'restartCount', 0)),
            'node' => (string) data_get($pod, 'spec.nodeName', ''),
            'age' => $this->age(data_get($pod, 'metadata.creationTimestamp')),
            'containers' => implode(', ', $containerNames),
            'container_names' => $containerNames,
            'application_uuid' => data_get($pod, 'metadata.labels')['coolify.io/application-uuid'] ?? null,
        ];
    }

    private function ready(Collection $containerStatuses, int $containerCount): string
    {
        if ($containerCount === 0) {
            return '0/0';
        }

        $ready = $containerStatuses->filter(fn (array $container) => data_get($container, 'ready') === true)->count();

        return "{$ready}/{$containerCount}";
    }

    private function containerNames(array $pod, Collection $containerStatuses): array
    {
        $names = $containerStatuses
            ->pluck('name')
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            $names = collect(data_get($pod, 'spec.containers', []))
                ->pluck('name')
                ->filter()
                ->values();
        }

        return $names
            ->map(fn (string $name) => (string) $name)
            ->toArray();
    }

    private function age(?string $createdAt): string
    {
        if (blank($createdAt)) {
            return '';
        }

        return Carbon::parse($createdAt)->diffForHumans(parts: 2, short: true);
    }
}
