<?php

namespace App\Services\Kubernetes;

use App\Models\Service;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

class KubernetesServiceManifestGenerator
{
    private KubernetesManifestData $data;

    public function __construct()
    {
        $this->data = new KubernetesManifestData;
    }

    public function generate(Service $service, array $compose, array $options = []): array
    {
        $namespace = $options['namespace'] ?? 'default';
        $resources = [];
        if (($options['create_namespace'] ?? false) === true) {
            $resources[] = $this->namespace($namespace);
        }
        if ($serviceAccount = $this->serviceAccount($namespace, $options)) {
            $resources[] = $serviceAccount;
        }
        foreach ($this->services($compose) as $serviceName => $composeService) {
            $resources = [
                ...$resources,
                ...$this->serviceResources($service, (string) $serviceName, $composeService, $namespace, $options),
            ];
        }

        return $resources;
    }

    public function toYaml(Service $service, array $compose, array $options = []): string
    {
        return collect($this->generate($service, $compose, $options))
            ->map(fn (array $resource) => Yaml::dump($resource, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK))
            ->implode("---\n");
    }

    public function resourceNames(Service $service, array $compose): array
    {
        return collect($this->services($compose))
            ->keys()
            ->map(fn (string $serviceName) => $this->resourceName($service, $serviceName))
            ->values()
            ->toArray();
    }

    private function serviceResources(Service $service, string $serviceName, array $composeService, string $namespace, array $options): array
    {
        $name = $this->resourceName($service, $serviceName);
        $labels = $this->labels($service, $name, $serviceName);
        $port = $this->port($service, $serviceName, $composeService);
        $environment = $this->environment($service, $composeService);
        $volumes = $this->volumes($service, $serviceName, $composeService, $name, $namespace, $labels, $options);
        $resources = collect($volumes['claims']);
        $secret = $this->secret($name, $namespace, $labels, $environment);
        if ($secret !== null) {
            $resources->push($secret);
        }
        $resources->push($this->deployment($name, $namespace, $labels, $composeService, $port, $secret !== null, $volumes, $options));
        $resources->push($this->service($name, $namespace, $labels, $port, $options));
        if ($ingress = $this->ingress($service, $serviceName, $name, $namespace, $labels, $options)) {
            $resources->push($ingress);
        }
        if (($options['autoscaling'] ?? false) === true) {
            $resources->push($this->horizontalPodAutoscaler($name, $namespace, $labels, $options));
        }
        if (($options['pod_disruption_budget_enabled'] ?? false) === true) {
            $resources->push($this->podDisruptionBudget($name, $namespace, $labels, $options));
        }

        return $resources->toArray();
    }

    private function deployment(string $name, string $namespace, array $labels, array $composeService, int $port, bool $hasSecret, array $volumes, array $options): array
    {
        $container = [
            'name' => 'application',
            'image' => $this->image($composeService, $name),
            'ports' => [['name' => 'http', 'containerPort' => $port, 'protocol' => 'TCP']],
        ];
        if ($hasSecret) {
            $container['envFrom'] = [['secretRef' => ['name' => "{$name}-env"]]];
        }
        if ($volumes['mounts'] !== []) {
            $container['volumeMounts'] = $volumes['mounts'];
        }
        $podSpec = ['containers' => [$container]];
        if ($volumes['volumes'] !== []) {
            $podSpec['volumes'] = $volumes['volumes'];
        }
        if (filled($options['service_account_name'] ?? null)) {
            $podSpec['serviceAccountName'] = $options['service_account_name'];
        }
        $imagePullSecrets = $this->data->stringList($options['image_pull_secrets'] ?? null);
        if ($imagePullSecrets !== []) {
            $podSpec['imagePullSecrets'] = collect($imagePullSecrets)->map(fn (string $secret) => ['name' => $secret])->values()->toArray();
        }
        $nodeSelector = $this->data->keyValueMap($options['node_selector'] ?? null);
        if ($nodeSelector !== []) {
            $podSpec['nodeSelector'] = $nodeSelector;
        }
        $tolerations = $this->data->yamlList($options['tolerations'] ?? null);
        if ($tolerations !== []) {
            $podSpec['tolerations'] = $tolerations;
        }

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'replicas' => (int) ($options['replicas'] ?? 1),
                'revisionHistoryLimit' => 3,
                'selector' => ['matchLabels' => $this->selector($name)],
                'template' => ['metadata' => ['labels' => $labels], 'spec' => $podSpec],
            ],
        ];
    }

    private function service(string $name, string $namespace, array $labels, int $port, array $options): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'type' => $options['service_type'] ?? 'ClusterIP',
                'selector' => $this->selector($name),
                'ports' => [['name' => 'http', 'protocol' => 'TCP', 'port' => 80, 'targetPort' => $port]],
            ],
        ];
    }

    private function ingress(Service $service, string $serviceName, string $name, string $namespace, array $labels, array $options): ?array
    {
        $host = $this->host($service, $serviceName);
        if ($host === null) {
            return null;
        }
        $metadata = ['name' => $name, 'namespace' => $namespace, 'labels' => $labels];
        $annotations = $this->data->keyValueMap($options['ingress_annotations'] ?? null);
        if ($annotations !== []) {
            $metadata['annotations'] = $annotations;
        }
        $spec = [
            'ingressClassName' => $options['ingress_class'] ?? 'traefik',
            'rules' => [[
                'host' => $host,
                'http' => ['paths' => [[
                    'path' => '/',
                    'pathType' => 'Prefix',
                    'backend' => ['service' => ['name' => $name, 'port' => ['number' => 80]]],
                ]]],
            ]],
        ];
        if (filled($options['ingress_tls_secret'] ?? null)) {
            $spec['tls'] = [['hosts' => [$host], 'secretName' => $options['ingress_tls_secret']]];
        }

        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => $metadata,
            'spec' => $spec,
        ];
    }

    private function horizontalPodAutoscaler(string $name, string $namespace, array $labels, array $options): array
    {
        return [
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'scaleTargetRef' => ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'name' => $name],
                'minReplicas' => (int) ($options['min_replicas'] ?? 1),
                'maxReplicas' => (int) ($options['max_replicas'] ?? 3),
                'metrics' => [[
                    'type' => 'Resource',
                    'resource' => ['name' => 'cpu', 'target' => ['type' => 'Utilization', 'averageUtilization' => (int) ($options['target_cpu_utilization_percentage'] ?? 70)]],
                ]],
            ],
        ];
    }

    private function secret(string $name, string $namespace, array $labels, array $environment): ?array
    {
        $data = collect($environment)
            ->filter(fn ($value, $key) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) === 1)
            ->map(fn ($value) => base64_encode((string) $value));

        return $data->isEmpty() ? null : [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => "{$name}-env", 'namespace' => $namespace, 'labels' => $labels],
            'type' => 'Opaque',
            'data' => $data->toArray(),
        ];
    }

    private function serviceAccount(string $namespace, array $options): ?array
    {
        $name = trim((string) ($options['service_account_name'] ?? ''));
        if ($name === '' || ($options['create_service_account'] ?? false) !== true) {
            return null;
        }
        $serviceAccount = [
            'apiVersion' => 'v1',
            'kind' => 'ServiceAccount',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'coolify'],
            ],
        ];
        $imagePullSecrets = $this->data->stringList($options['image_pull_secrets'] ?? null);
        if ($imagePullSecrets !== []) {
            $serviceAccount['imagePullSecrets'] = collect($imagePullSecrets)->map(fn (string $secret) => ['name' => $secret])->values()->toArray();
        }

        return $serviceAccount;
    }

    private function podDisruptionBudget(string $name, string $namespace, array $labels, array $options): array
    {
        return [
            'apiVersion' => 'policy/v1',
            'kind' => 'PodDisruptionBudget',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'minAvailable' => $this->data->intOrPercent($options['pod_disruption_budget_min_available'] ?? null, 1),
                'selector' => ['matchLabels' => $this->selector($name)],
            ],
        ];
    }

    private function volumes(Service $service, string $serviceName, array $composeService, string $name, string $namespace, array $labels, array $options): array
    {
        $claims = [];
        $mounts = [];
        $volumes = [];
        foreach (data_get($composeService, 'volumes', []) as $index => $volume) {
            [$source, $target] = $this->volumeParts($volume);
            if ($source === null || $target === null) {
                continue;
            }
            if ($this->isBindMount($source)) {
                throw new InvalidArgumentException("Kubernetes service {$serviceName} uses bind mount {$source}. Use named volumes for Kubernetes destinations.");
            }
            $volumeName = $this->data->dnsLabel($source, "storage-{$index}");
            $claimName = $this->data->dnsLabel($this->resourceName($service, $source), 'storage');
            $mounts[] = ['name' => $volumeName, 'mountPath' => $target];
            $volumes[] = ['name' => $volumeName, 'persistentVolumeClaim' => ['claimName' => $claimName]];
            $claims[$claimName] ??= $this->claim($claimName, $namespace, $labels, $options);
        }

        return ['claims' => array_values($claims), 'mounts' => $mounts, 'volumes' => $volumes];
    }

    private function claim(string $name, string $namespace, array $labels, array $options): array
    {
        $spec = ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => $options['storage_size'] ?? '1Gi']]];
        if (filled($options['storage_class'] ?? null)) {
            $spec['storageClassName'] = $options['storage_class'];
        }

        return ['apiVersion' => 'v1', 'kind' => 'PersistentVolumeClaim', 'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels], 'spec' => $spec];
    }

    private function namespace(string $namespace): array
    {
        return ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'coolify']]];
    }

    private function services(array $compose): array
    {
        $services = data_get($compose, 'services', []);
        if ($services === []) {
            throw new InvalidArgumentException('Kubernetes services require at least one Compose service.');
        }

        return $services;
    }

    private function image(array $composeService, string $name): string
    {
        if (filled($composeService['build'] ?? null)) {
            throw new InvalidArgumentException("Kubernetes service {$name} uses build. Use an image for Kubernetes destinations.");
        }
        if (blank($composeService['image'] ?? null)) {
            throw new InvalidArgumentException("Kubernetes service {$name} requires an image for Kubernetes destinations.");
        }

        return $composeService['image'];
    }

    private function environment(Service $service, array $composeService): array
    {
        $environmentVariables = $service->relationLoaded('environment_variables')
            ? $service->getRelation('environment_variables')
            : ($service->exists ? $service->environment_variables()->get() : collect());
        $environment = $environmentVariables->mapWithKeys(fn ($env) => [$env->key => $env->real_value])->toArray();
        foreach (data_get($composeService, 'environment', []) as $key => $value) {
            if (is_int($key)) {
                [$key, $value] = array_pad(explode('=', (string) $value, 2), 2, '');
            }
            $environment[(string) $key] = (string) $value;
        }

        return $environment;
    }

    private function port(Service $service, string $serviceName, array $composeService): int
    {
        $serviceResource = $service->applications->firstWhere('name', $serviceName) ?? $service->databases->firstWhere('name', $serviceName);
        $requiredPort = $serviceResource?->getRequiredPort();
        if ($requiredPort !== null) {
            return (int) $requiredPort;
        }
        $port = data_get($composeService, 'ports.0') ?? data_get($composeService, 'expose.0');
        if (is_array($port)) {
            return (int) ($port['target'] ?? $port['published'] ?? 80);
        }
        if (is_numeric($port)) {
            return (int) $port;
        }
        if (is_string($port) && preg_match('/(?::|^)(\d+)(?:\/[a-z]+)?$/i', $port, $matches) === 1) {
            return (int) $matches[1];
        }

        return 80;
    }

    private function volumeParts(string|array $volume): array
    {
        if (is_array($volume)) {
            return [data_get($volume, 'source'), data_get($volume, 'target')];
        }
        $parts = explode(':', $volume);

        return count($parts) >= 2 ? [$parts[0], $parts[1]] : [null, null];
    }

    private function isBindMount(string $source): bool
    {
        return str_starts_with($source, '/') || str_starts_with($source, '.') || str_starts_with($source, '~');
    }

    private function host(Service $service, string $serviceName): ?string
    {
        $serviceResource = $service->applications->firstWhere('name', $serviceName) ?? $service->databases->firstWhere('name', $serviceName);
        $fqdn = trim(explode(',', (string) data_get($serviceResource, 'fqdn'))[0]);
        if ($fqdn === '') {
            return null;
        }

        return parse_url($fqdn, PHP_URL_HOST) ?: $fqdn;
    }

    private function resourceName(Service $service, string $serviceName): string
    {
        $base = str(($service->name ?: 'service').'-'.$serviceName)
            ->lower()
            ->replaceMatches('/[^a-z0-9-]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->toString();

        return $this->data->dnsLabel($base.'-'.substr((string) $service->uuid, 0, 8), 'service');
    }

    private function labels(Service $service, string $name, string $serviceName): array
    {
        return [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/managed-by' => 'coolify',
            'app.kubernetes.io/component' => 'service',
            'coolify.io/service-uuid' => (string) $service->uuid,
            'coolify.io/compose-service' => $this->data->dnsLabel($serviceName, 'service'),
        ];
    }

    private function selector(string $name): array
    {
        return ['app.kubernetes.io/name' => $name, 'app.kubernetes.io/managed-by' => 'coolify'];
    }
}
