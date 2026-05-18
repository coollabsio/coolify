<?php

namespace App\Services\Kubernetes;

use App\Models\Application;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

class KubernetesApplicationManifestGenerator
{
    /**
     * @param  array{namespace?: string, ingress_class?: string, service_type?: string, replicas?: int, min_replicas?: int, max_replicas?: int, autoscaling?: bool, target_cpu_utilization_percentage?: int, image?: string, environment?: array<string, string>, deployment_uuid?: string}  $options
     * @return array<int, array<string, mixed>>
     */
    public function generate(Application $application, array $options = []): array
    {
        $name = $this->resourceName($application);
        $namespace = $this->namespace($options['namespace'] ?? null);
        $port = $this->containerPort($application);
        $labels = $this->labels($application, $name);

        $resources = [];
        $secret = $this->secret($name, $namespace, $labels, $options);

        if ($secret !== null) {
            $resources[] = $secret;
        }

        $resources[] = $this->deployment($application, $name, $namespace, $port, $labels, $options, $secret !== null);
        $resources[] = $this->service($name, $namespace, $port, $labels, $options);

        $ingress = $this->ingress($application, $name, $namespace, $labels, $options);

        if ($ingress !== null) {
            $resources[] = $ingress;
        }

        if (($options['autoscaling'] ?? false) === true) {
            $resources[] = $this->horizontalPodAutoscaler($name, $namespace, $labels, $options);
        }

        return $resources;
    }

    /**
     * @param  array{namespace?: string, ingress_class?: string, service_type?: string, replicas?: int, min_replicas?: int, max_replicas?: int, autoscaling?: bool, target_cpu_utilization_percentage?: int, image?: string, environment?: array<string, string>, deployment_uuid?: string}  $options
     */
    public function toYaml(Application $application, array $options = []): string
    {
        return collect($this->generate($application, $options))
            ->map(fn (array $resource) => Yaml::dump($resource, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK))
            ->implode("---\n");
    }

    public function resourceName(Application $application): string
    {
        $name = str($application->name ?: 'application')
            ->lower()
            ->replaceMatches('/[^a-z0-9-]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->toString();

        if ($name === '') {
            $name = 'application';
        }

        $suffix = substr((string) $application->uuid, 0, 8);
        $name = substr($name, 0, 54 - strlen($suffix));

        return trim($name, '-').'-'.$suffix;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array{replicas?: int, image?: string, deployment_uuid?: string}  $options
     * @return array<string, mixed>
     */
    private function deployment(Application $application, string $name, string $namespace, int $port, array $labels, array $options, bool $hasSecret): array
    {
        $container = [
            'name' => 'application',
            'image' => $this->image($application, $options),
            'ports' => [
                [
                    'name' => 'http',
                    'containerPort' => $port,
                    'protocol' => 'TCP',
                ],
            ],
        ];

        if ($hasSecret) {
            $container['envFrom'] = [
                [
                    'secretRef' => [
                        'name' => $this->secretName($name),
                    ],
                ],
            ];
        }

        $healthCheck = $this->httpHealthCheck($application, $port);

        if ($healthCheck !== null) {
            $container['readinessProbe'] = $healthCheck;
            $container['livenessProbe'] = $healthCheck;
        }

        $resources = $this->resources($application);

        if ($resources !== []) {
            $container['resources'] = $resources;
        }

        $templateMetadata = [
            'labels' => $labels,
        ];

        if (filled($options['deployment_uuid'] ?? null)) {
            $templateMetadata['annotations'] = [
                'coolify.io/deployment-uuid' => $options['deployment_uuid'],
            ];
        }

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'replicas' => (int) ($options['replicas'] ?? 1),
                'revisionHistoryLimit' => 3,
                'strategy' => [
                    'type' => 'RollingUpdate',
                    'rollingUpdate' => [
                        'maxUnavailable' => 0,
                        'maxSurge' => 1,
                    ],
                ],
                'selector' => [
                    'matchLabels' => [
                        'app.kubernetes.io/name' => $name,
                        'app.kubernetes.io/managed-by' => 'coolify',
                    ],
                ],
                'template' => [
                    'metadata' => $templateMetadata,
                    'spec' => [
                        'containers' => [$container],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array{environment?: array<string, string>}  $options
     * @return array<string, mixed>|null
     */
    private function secret(string $name, string $namespace, array $labels, array $options): ?array
    {
        $environment = collect($options['environment'] ?? [])
            ->filter(fn ($value, $key) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) === 1)
            ->map(fn ($value) => base64_encode((string) $value));

        if ($environment->isEmpty()) {
            return null;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $this->secretName($name),
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'type' => 'Opaque',
            'data' => $environment->toArray(),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array{service_type?: string}  $options
     * @return array<string, mixed>
     */
    private function service(string $name, string $namespace, int $port, array $labels, array $options): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'type' => $options['service_type'] ?? 'ClusterIP',
                'selector' => [
                    'app.kubernetes.io/name' => $name,
                    'app.kubernetes.io/managed-by' => 'coolify',
                ],
                'ports' => [
                    [
                        'name' => 'http',
                        'protocol' => 'TCP',
                        'port' => 80,
                        'targetPort' => $port,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array{ingress_class?: string}  $options
     * @return array<string, mixed>|null
     */
    private function ingress(Application $application, string $name, string $namespace, array $labels, array $options): ?array
    {
        $host = $this->host($application);

        if ($host === null) {
            return null;
        }

        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'ingressClassName' => $options['ingress_class'] ?? 'traefik',
                'rules' => [
                    [
                        'host' => $host,
                        'http' => [
                            'paths' => [
                                [
                                    'path' => '/',
                                    'pathType' => 'Prefix',
                                    'backend' => [
                                        'service' => [
                                            'name' => $name,
                                            'port' => [
                                                'number' => 80,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array{min_replicas?: int, max_replicas?: int, target_cpu_utilization_percentage?: int}  $options
     * @return array<string, mixed>
     */
    private function horizontalPodAutoscaler(string $name, string $namespace, array $labels, array $options): array
    {
        return [
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'scaleTargetRef' => [
                    'apiVersion' => 'apps/v1',
                    'kind' => 'Deployment',
                    'name' => $name,
                ],
                'minReplicas' => (int) ($options['min_replicas'] ?? 1),
                'maxReplicas' => (int) ($options['max_replicas'] ?? 3),
                'metrics' => [
                    [
                        'type' => 'Resource',
                        'resource' => [
                            'name' => 'cpu',
                            'target' => [
                                'type' => 'Utilization',
                                'averageUtilization' => (int) ($options['target_cpu_utilization_percentage'] ?? 70),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpHealthCheck(Application $application, int $port): ?array
    {
        if (! $application->health_check_enabled || $application->health_check_type === 'cmd') {
            return null;
        }

        return [
            'httpGet' => [
                'path' => $application->health_check_path ?: '/',
                'port' => (int) ($application->health_check_port ?: $port),
                'scheme' => strtoupper($application->health_check_scheme ?: 'HTTP'),
            ],
            'periodSeconds' => (int) ($application->health_check_interval ?: 30),
            'timeoutSeconds' => (int) ($application->health_check_timeout ?: 5),
            'failureThreshold' => (int) ($application->health_check_retries ?: 3),
            'initialDelaySeconds' => (int) ($application->health_check_start_period ?: 10),
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function resources(Application $application): array
    {
        $limits = array_filter([
            'memory' => $application->limits_memory ?: null,
            'cpu' => $application->limits_cpus ?: null,
        ]);

        $requests = array_filter([
            'memory' => $application->limits_memory_reservation ?: null,
        ]);

        return array_filter([
            'limits' => $limits,
            'requests' => $requests,
        ]);
    }

    private function image(Application $application, array $options): string
    {
        if (filled($options['image'] ?? null)) {
            return $options['image'];
        }

        if (! $application->docker_registry_image_name) {
            throw new InvalidArgumentException('Kubernetes deployments require docker_registry_image_name.');
        }

        if (! $application->docker_registry_image_tag) {
            return $application->docker_registry_image_name;
        }

        return $application->docker_registry_image_name.':'.$application->docker_registry_image_tag;
    }

    private function containerPort(Application $application): int
    {
        $ports = collect(preg_split('/[\s,]+/', (string) $application->ports_exposes))
            ->map(fn (string $port) => trim($port))
            ->filter(fn (string $port) => is_numeric($port) && (int) $port > 0)
            ->values();

        return (int) ($ports->first() ?: 3000);
    }

    private function host(Application $application): ?string
    {
        $fqdn = trim(explode(',', (string) $application->fqdn)[0]);

        if ($fqdn === '') {
            return null;
        }

        return parse_url($fqdn, PHP_URL_HOST) ?: $fqdn;
    }

    private function namespace(?string $namespace): string
    {
        return $namespace ?: 'default';
    }

    private function secretName(string $name): string
    {
        return "{$name}-env";
    }

    /**
     * @return array<string, string>
     */
    private function labels(Application $application, string $name): array
    {
        return [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/managed-by' => 'coolify',
            'app.kubernetes.io/component' => 'application',
            'coolify.io/application-uuid' => (string) $application->uuid,
        ];
    }
}
