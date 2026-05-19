<?php

namespace App\Services\Kubernetes;

use App\Models\Application;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

class KubernetesComposeManifestGenerator
{
    private KubernetesManifestData $data;

    public function __construct()
    {
        $this->data = new KubernetesManifestData;
    }

    public function generate(Application $application, array $compose, array $options = []): array
    {
        $namespace = $options['namespace'] ?? 'default';
        $resources = [];

        if (($options['create_namespace'] ?? false) === true) {
            $resources[] = $this->namespace($namespace);
        }

        if ($serviceAccount = $this->serviceAccount($namespace, $options)) {
            $resources[] = $serviceAccount;
        }

        foreach ($this->services($compose) as $serviceName => $service) {
            $resources = [
                ...$resources,
                ...$this->serviceResources($application, $compose, (string) $serviceName, $service, $namespace, $options),
            ];
        }

        return $resources;
    }

    public function toYaml(Application $application, array $compose, array $options = []): string
    {
        return collect($this->generate($application, $compose, $options))
            ->map(fn (array $resource) => Yaml::dump($resource, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK))
            ->implode("---\n");
    }

    public function resourceNames(Application $application, array $compose): array
    {
        return collect($this->services($compose))
            ->keys()
            ->map(fn (string $serviceName) => $this->resourceName($application, $serviceName))
            ->values()
            ->toArray();
    }

    private function serviceResources(Application $application, array $compose, string $serviceName, array $service, string $namespace, array $options): array
    {
        $name = $this->resourceName($application, $serviceName);
        $labels = $this->labels($application, $name, $serviceName);
        $port = $this->port($service);
        $environment = $this->environment($service, $options['environment'] ?? []);
        $volumes = $this->volumes($application, $compose, $serviceName, $service, $name, $namespace, $labels, $options);
        $resources = collect($volumes['claims']);
        $secret = $this->secret($name, $namespace, $labels, $environment);

        if ($secret !== null) {
            $resources->push($secret);
        }

        $resources->push($this->deployment($name, $namespace, $labels, $service, $port, $secret !== null, $volumes, $options));
        $resources->push($this->service($name, $namespace, $labels, $port, $options));

        if ($ingress = $this->ingress($application, $name, $namespace, $labels, $serviceName, $options)) {
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

    private function deployment(string $name, string $namespace, array $labels, array $service, int $port, bool $hasSecret, array $volumes, array $options): array
    {
        $container = [
            'name' => 'application',
            'image' => $this->image($service, $name),
            'ports' => [[
                'name' => 'http',
                'containerPort' => $port,
                'protocol' => 'TCP',
            ]],
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
            'spec' => ['replicas' => (int) ($options['replicas'] ?? 1), 'revisionHistoryLimit' => 3, 'selector' => ['matchLabels' => $this->selector($name)], 'template' => ['metadata' => ['labels' => $labels], 'spec' => $podSpec]],
        ];
    }

    private function service(string $name, string $namespace, array $labels, int $port, array $options): array
    {
        return ['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels], 'spec' => ['type' => $options['service_type'] ?? 'ClusterIP', 'selector' => $this->selector($name), 'ports' => [['name' => 'http', 'protocol' => 'TCP', 'port' => 80, 'targetPort' => $port]]]];
    }

    private function ingress(Application $application, string $name, string $namespace, array $labels, string $serviceName, array $options): ?array
    {
        $host = $this->host($application, $serviceName);

        if ($host === null) {
            return null;
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

        $metadata = ['name' => $name, 'namespace' => $namespace, 'labels' => $labels];
        $annotations = $this->data->keyValueMap($options['ingress_annotations'] ?? null);
        if ($annotations !== []) {
            $metadata['annotations'] = $annotations;
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
        return ['apiVersion' => 'autoscaling/v2', 'kind' => 'HorizontalPodAutoscaler', 'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels], 'spec' => ['scaleTargetRef' => ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'name' => $name], 'minReplicas' => (int) ($options['min_replicas'] ?? 1), 'maxReplicas' => (int) ($options['max_replicas'] ?? 3), 'metrics' => [['type' => 'Resource', 'resource' => ['name' => 'cpu', 'target' => ['type' => 'Utilization', 'averageUtilization' => (int) ($options['target_cpu_utilization_percentage'] ?? 70)]]]]]];
    }

    private function serviceAccount(string $namespace, array $options): ?array
    {
        $name = trim((string) ($options['service_account_name'] ?? ''));
        if ($name === '' || ($options['create_service_account'] ?? false) !== true) {
            return null;
        }

        $serviceAccount = ['apiVersion' => 'v1', 'kind' => 'ServiceAccount', 'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'coolify']]];
        $imagePullSecrets = $this->data->stringList($options['image_pull_secrets'] ?? null);
        if ($imagePullSecrets !== []) {
            $serviceAccount['imagePullSecrets'] = collect($imagePullSecrets)->map(fn (string $secret) => ['name' => $secret])->values()->toArray();
        }

        return $serviceAccount;
    }

    private function podDisruptionBudget(string $name, string $namespace, array $labels, array $options): array
    {
        return ['apiVersion' => 'policy/v1', 'kind' => 'PodDisruptionBudget', 'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels], 'spec' => ['minAvailable' => $this->data->intOrPercent($options['pod_disruption_budget_min_available'] ?? null, 1), 'selector' => ['matchLabels' => $this->selector($name)]]];
    }

    private function secret(string $name, string $namespace, array $labels, array $environment): ?array
    {
        $data = collect($environment)
            ->filter(fn ($value, $key) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) === 1)
            ->map(fn ($value) => base64_encode((string) $value));

        if ($data->isEmpty()) {
            return null;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => "{$name}-env", 'namespace' => $namespace, 'labels' => $labels],
            'type' => 'Opaque',
            'data' => $data->toArray(),
        ];
    }

    private function volumes(Application $application, array $compose, string $serviceName, array $service, string $name, string $namespace, array $labels, array $options): array
    {
        $claims = [];
        $mounts = [];
        $volumes = [];

        foreach (data_get($service, 'volumes', []) as $index => $volume) {
            [$source, $target] = $this->volumeParts($volume);

            if ($source === null || $target === null) {
                continue;
            }

            if ($this->isBindMount($source)) {
                throw new InvalidArgumentException("Kubernetes Compose service {$serviceName} uses bind mount {$source}. Use named volumes for Kubernetes destinations.");
            }

            $volumeName = $this->data->dnsLabel($source, "storage-{$index}");
            $claimName = $this->data->dnsLabel($this->resourceName($application, $source), 'storage');
            $mounts[] = ['name' => $volumeName, 'mountPath' => $target];
            $volumes[] = ['name' => $volumeName, 'persistentVolumeClaim' => ['claimName' => $claimName]];

            if (! isset($claims[$claimName])) {
                $claims[$claimName] = $this->claim($claimName, $namespace, $labels, $options);
            }
        }

        return ['claims' => array_values($claims), 'mounts' => $mounts, 'volumes' => $volumes];
    }

    private function claim(string $name, string $namespace, array $labels, array $options): array
    {
        $spec = [
            'accessModes' => ['ReadWriteOnce'],
            'resources' => ['requests' => ['storage' => $options['storage_size'] ?? '1Gi']],
        ];

        if (filled($options['storage_class'] ?? null)) {
            $spec['storageClassName'] = $options['storage_class'];
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => $spec,
        ];
    }

    private function namespace(string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => ['name' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'coolify']],
        ];
    }

    private function services(array $compose): array
    {
        $services = data_get($compose, 'services', []);

        if ($services === []) {
            throw new InvalidArgumentException('Kubernetes Compose deployments require at least one service.');
        }

        return $services;
    }

    private function image(array $service, string $name): string
    {
        if (filled($service['build'] ?? null)) {
            throw new InvalidArgumentException("Kubernetes Compose service {$name} uses build. Use an image for Kubernetes destinations.");
        }

        if (blank($service['image'] ?? null)) {
            throw new InvalidArgumentException("Kubernetes Compose service {$name} requires an image for Kubernetes destinations.");
        }

        return $service['image'];
    }

    private function environment(array $service, array $runtimeEnvironment): array
    {
        $environment = $runtimeEnvironment;

        foreach (data_get($service, 'environment', []) as $key => $value) {
            if (is_int($key)) {
                [$key, $value] = array_pad(explode('=', (string) $value, 2), 2, '');
            }

            $environment[(string) $key] = (string) $value;
        }

        return $environment;
    }

    private function port(array $service): int
    {
        $port = data_get($service, 'ports.0') ?? data_get($service, 'expose.0');

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

    private function host(Application $application, string $serviceName): ?string
    {
        $domains = json_decode((string) $application->docker_compose_domains, associative: true) ?: [];
        $domain = $domains[$serviceName]['domain'] ?? null;
        $domain = trim(explode(',', (string) $domain)[0]);

        if ($domain === '') {
            return null;
        }

        return parse_url($domain, PHP_URL_HOST) ?: $domain;
    }

    private function resourceName(Application $application, string $serviceName): string
    {
        $base = str(($application->name ?: 'application').'-'.$serviceName)
            ->lower()
            ->replaceMatches('/[^a-z0-9-]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->toString();

        return $this->data->dnsLabel($base.'-'.substr((string) $application->uuid, 0, 8), 'application');
    }

    private function labels(Application $application, string $name, string $serviceName): array
    {
        return [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/managed-by' => 'coolify',
            'app.kubernetes.io/component' => 'compose-service',
            'coolify.io/application-uuid' => (string) $application->uuid,
            'coolify.io/compose-service' => $this->data->dnsLabel($serviceName, 'service'),
        ];
    }

    private function selector(string $name): array
    {
        return [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/managed-by' => 'coolify',
        ];
    }
}
