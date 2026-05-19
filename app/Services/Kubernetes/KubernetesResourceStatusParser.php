<?php

namespace App\Services\Kubernetes;

use Illuminate\Support\Carbon;
use JsonException;

class KubernetesResourceStatusParser
{
    /**
     * @return array<int, array{
     *     kind: string,
     *     name: string,
     *     namespace: string,
     *     status: string,
     *     detail: string,
     *     age: string,
     *     application_uuid: ?string
     * }>
     *
     * @throws JsonException
     */
    public function parse(string $json): array
    {
        $payload = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        return collect(data_get($payload, 'items', []))
            ->map(fn (array $resource) => $this->resource($resource))
            ->filter(fn (array $resource) => $resource['kind'] !== '' && $resource['name'] !== '')
            ->sortBy([['kind', 'asc'], ['name', 'asc']])
            ->values()
            ->toArray();
    }

    private function resource(array $resource): array
    {
        $kind = (string) data_get($resource, 'kind', '');

        return [
            'kind' => $kind,
            'name' => (string) data_get($resource, 'metadata.name', ''),
            'namespace' => (string) data_get($resource, 'metadata.namespace', ''),
            'status' => $this->status($kind, $resource),
            'detail' => $this->detail($kind, $resource),
            'age' => $this->age(data_get($resource, 'metadata.creationTimestamp')),
            'application_uuid' => data_get($resource, 'metadata.labels.coolify\.io/application-uuid')
                ?? data_get($resource, 'metadata.labels')['coolify.io/application-uuid'] ?? null,
        ];
    }

    private function status(string $kind, array $resource): string
    {
        return match ($kind) {
            'Deployment', 'StatefulSet' => $this->workloadStatus($resource),
            'Service' => (string) data_get($resource, 'spec.type', 'Service'),
            'Ingress' => empty(data_get($resource, 'status.loadBalancer.ingress', [])) ? 'Pending' : 'Ready',
            'HorizontalPodAutoscaler' => $this->hpaStatus($resource),
            'PodDisruptionBudget' => $this->pdbStatus($resource),
            'PersistentVolumeClaim' => (string) data_get($resource, 'status.phase', 'Pending'),
            'Secret', 'ServiceAccount' => 'Ready',
            default => (string) data_get($resource, 'status.phase', 'Unknown'),
        };
    }

    private function detail(string $kind, array $resource): string
    {
        return match ($kind) {
            'Deployment', 'StatefulSet' => $this->workloadDetail($resource),
            'Service' => $this->serviceDetail($resource),
            'Ingress' => $this->ingressDetail($resource),
            'HorizontalPodAutoscaler' => $this->hpaDetail($resource),
            'PodDisruptionBudget' => $this->pdbDetail($resource),
            'PersistentVolumeClaim' => $this->pvcDetail($resource),
            default => '',
        };
    }

    private function workloadStatus(array $resource): string
    {
        $desired = (int) data_get($resource, 'spec.replicas', 0);
        $available = (int) data_get($resource, 'status.availableReplicas', 0);

        if ($desired === 0) {
            return 'Stopped';
        }

        return $available >= $desired ? 'Ready' : 'Progressing';
    }

    private function workloadDetail(array $resource): string
    {
        $desired = (int) data_get($resource, 'spec.replicas', 0);
        $ready = (int) data_get($resource, 'status.readyReplicas', 0);
        $available = (int) data_get($resource, 'status.availableReplicas', 0);

        return "ready {$ready}/{$desired}, available {$available}";
    }

    private function serviceDetail(array $resource): string
    {
        return collect(data_get($resource, 'spec.ports', []))
            ->map(fn (array $port) => data_get($port, 'port').':'.data_get($port, 'targetPort'))
            ->filter(fn (string $port) => $port !== ':')
            ->implode(', ');
    }

    private function ingressDetail(array $resource): string
    {
        return collect(data_get($resource, 'spec.rules', []))
            ->pluck('host')
            ->filter()
            ->implode(', ');
    }

    private function hpaStatus(array $resource): string
    {
        $desired = data_get($resource, 'status.desiredReplicas');
        $current = data_get($resource, 'status.currentReplicas');

        if ($desired === null || $current === null) {
            return 'Pending';
        }

        return ((int) $current >= (int) $desired) ? 'Ready' : 'Scaling';
    }

    private function hpaDetail(array $resource): string
    {
        return 'replicas '.(int) data_get($resource, 'status.currentReplicas', 0).'/'.(int) data_get($resource, 'status.desiredReplicas', 0);
    }

    private function pdbStatus(array $resource): string
    {
        return ((int) data_get($resource, 'status.disruptionsAllowed', 0) > 0) ? 'Ready' : 'Limited';
    }

    private function pdbDetail(array $resource): string
    {
        return 'allowed '.(int) data_get($resource, 'status.disruptionsAllowed', 0).', healthy '.(int) data_get($resource, 'status.currentHealthy', 0);
    }

    private function pvcDetail(array $resource): string
    {
        $capacity = (string) data_get($resource, 'status.capacity.storage', '');
        $class = (string) data_get($resource, 'spec.storageClassName', '');

        return trim(implode(' ', array_filter([$capacity, $class])));
    }

    private function age(?string $createdAt): string
    {
        if (blank($createdAt)) {
            return '';
        }

        return Carbon::parse($createdAt)->diffForHumans(parts: 2, short: true);
    }
}
