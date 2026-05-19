<?php

namespace App\Services\Kubernetes;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use Illuminate\Support\Collection;

class KubernetesApplicationManifestExtras
{
    private KubernetesManifestData $data;

    public function __construct(
        private Application $application,
        private string $name,
        private string $namespace,
        private array $labels,
        private array $options,
    ) {
        $this->data = new KubernetesManifestData;
    }

    public function prefixResources(): array
    {
        $resources = [];

        if (($this->options['create_namespace'] ?? false) === true) {
            $resources[] = $this->namespaceResource();
        }

        $serviceAccount = $this->serviceAccount();

        if ($serviceAccount !== null) {
            $resources[] = $serviceAccount;
        }

        foreach ($this->persistentVolumeClaims() as $persistentVolumeClaim) {
            $resources[] = $persistentVolumeClaim;
        }

        return $resources;
    }

    public function suffixResources(): array
    {
        if (($this->options['pod_disruption_budget_enabled'] ?? false) !== true) {
            return [];
        }

        return [$this->podDisruptionBudget()];
    }

    public function container(array $container): array
    {
        $volumeMounts = $this->volumeMounts();

        if ($volumeMounts !== []) {
            $container['volumeMounts'] = $volumeMounts;
        }

        return $container;
    }

    public function podSpec(array $container): array
    {
        $podSpec = [
            'containers' => [$container],
        ];
        $serviceAccountName = trim((string) ($this->options['service_account_name'] ?? ''));

        if ($serviceAccountName !== '') {
            $podSpec['serviceAccountName'] = $serviceAccountName;
        }

        $imagePullSecrets = $this->data->stringList($this->options['image_pull_secrets'] ?? null);

        if ($imagePullSecrets !== []) {
            $podSpec['imagePullSecrets'] = collect($imagePullSecrets)
                ->map(fn (string $secret) => ['name' => $secret])
                ->values()
                ->toArray();
        }

        $nodeSelector = $this->data->keyValueMap($this->options['node_selector'] ?? null);

        if ($nodeSelector !== []) {
            $podSpec['nodeSelector'] = $nodeSelector;
        }

        $tolerations = $this->data->yamlList($this->options['tolerations'] ?? null);

        if ($tolerations !== []) {
            $podSpec['tolerations'] = $tolerations;
        }

        $volumes = $this->volumes();

        if ($volumes !== []) {
            $podSpec['volumes'] = $volumes;
        }

        return $podSpec;
    }

    public function ingressMetadata(array $metadata): array
    {
        $annotations = $this->data->keyValueMap($this->options['ingress_annotations'] ?? null);

        if ($annotations !== []) {
            $metadata['annotations'] = $annotations;
        }

        return $metadata;
    }

    public function ingressSpec(array $spec, string $host): array
    {
        $tlsSecret = trim((string) ($this->options['ingress_tls_secret'] ?? ''));

        if ($tlsSecret !== '') {
            $spec['tls'] = [
                [
                    'hosts' => [$host],
                    'secretName' => $tlsSecret,
                ],
            ];
        }

        return $spec;
    }

    private function namespaceResource(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $this->namespace,
                'labels' => [
                    'app.kubernetes.io/managed-by' => 'coolify',
                ],
            ],
        ];
    }

    private function serviceAccount(): ?array
    {
        $name = trim((string) ($this->options['service_account_name'] ?? ''));

        if ($name === '' || ($this->options['create_service_account'] ?? false) !== true) {
            return null;
        }

        $serviceAccount = [
            'apiVersion' => 'v1',
            'kind' => 'ServiceAccount',
            'metadata' => [
                'name' => $name,
                'namespace' => $this->namespace,
                'labels' => $this->labels,
            ],
        ];
        $imagePullSecrets = $this->data->stringList($this->options['image_pull_secrets'] ?? null);

        if ($imagePullSecrets !== []) {
            $serviceAccount['imagePullSecrets'] = collect($imagePullSecrets)
                ->map(fn (string $secret) => ['name' => $secret])
                ->values()
                ->toArray();
        }

        return $serviceAccount;
    }

    private function persistentVolumeClaims(): array
    {
        return $this->persistentStorages()
            ->map(fn (LocalPersistentVolume $storage, int $index) => $this->persistentVolumeClaim($storage, $index))
            ->values()
            ->toArray();
    }

    private function persistentVolumeClaim(LocalPersistentVolume $storage, int $index): array
    {
        $spec = [
            'accessModes' => ['ReadWriteOnce'],
            'resources' => [
                'requests' => [
                    'storage' => $this->options['storage_size'] ?? '1Gi',
                ],
            ],
        ];

        if (filled($this->options['storage_class'] ?? null)) {
            $spec['storageClassName'] = $this->options['storage_class'];
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => [
                'name' => $this->persistentVolumeClaimName($storage, $index),
                'namespace' => $this->namespace,
                'labels' => $this->labels,
            ],
            'spec' => $spec,
        ];
    }

    private function podDisruptionBudget(): array
    {
        return [
            'apiVersion' => 'policy/v1',
            'kind' => 'PodDisruptionBudget',
            'metadata' => [
                'name' => $this->name,
                'namespace' => $this->namespace,
                'labels' => $this->labels,
            ],
            'spec' => [
                'minAvailable' => $this->data->intOrPercent($this->options['pod_disruption_budget_min_available'] ?? null, 1),
                'selector' => [
                    'matchLabels' => [
                        'app.kubernetes.io/name' => $this->name,
                        'app.kubernetes.io/managed-by' => 'coolify',
                    ],
                ],
            ],
        ];
    }

    private function persistentVolumeClaimName(LocalPersistentVolume $storage, int $index): string
    {
        return $this->data->dnsLabel($this->name.'-'.$this->volumeName($storage, $index), 'storage');
    }

    private function volumeName(LocalPersistentVolume $storage, int $index): string
    {
        return $this->data->dnsLabel($storage->name ?: $storage->mount_path, "storage-{$index}");
    }

    private function volumeMounts(): array
    {
        return $this->persistentStorages()
            ->map(fn (LocalPersistentVolume $storage, int $index) => [
                'name' => $this->volumeName($storage, $index),
                'mountPath' => $storage->mount_path,
            ])
            ->values()
            ->toArray();
    }

    private function volumes(): array
    {
        return $this->persistentStorages()
            ->map(fn (LocalPersistentVolume $storage, int $index) => [
                'name' => $this->volumeName($storage, $index),
                'persistentVolumeClaim' => [
                    'claimName' => $this->persistentVolumeClaimName($storage, $index),
                ],
            ])
            ->values()
            ->toArray();
    }

    private function persistentStorages(): Collection
    {
        if ($this->application->relationLoaded('persistentStorages')) {
            return $this->application->getRelation('persistentStorages');
        }

        if (! $this->application->exists) {
            return collect();
        }

        return $this->application->persistentStorages()->get();
    }
}
