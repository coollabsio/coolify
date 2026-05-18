<?php

use App\Models\Application;
use App\Services\Kubernetes\KubernetesApplicationManifestGenerator;
use Symfony\Component\Yaml\Yaml;

function kubernetesApplication(array $attributes = []): Application
{
    return new Application(array_merge([
        'name' => 'Customer API',
        'uuid' => 'ckv4a1b2c3d4e5f6g7h8i9j0',
        'fqdn' => 'https://api.example.com',
        'docker_registry_image_name' => 'registry.example.com/customer/api',
        'docker_registry_image_tag' => '2026.05.18',
        'ports_exposes' => '8080',
        'health_check_enabled' => true,
        'health_check_path' => '/health',
        'health_check_scheme' => 'HTTP',
        'health_check_interval' => 15,
        'health_check_timeout' => 3,
        'health_check_retries' => 4,
        'health_check_start_period' => 20,
        'limits_memory' => '512Mi',
        'limits_memory_reservation' => '256Mi',
        'limits_cpus' => '500m',
    ], $attributes));
}

it('generates deployment service ingress and hpa manifests', function () {
    $generator = new KubernetesApplicationManifestGenerator;

    $resources = $generator->generate(kubernetesApplication(), [
        'namespace' => 'coolify-production',
        'ingress_class' => 'nginx',
        'autoscaling' => true,
        'min_replicas' => 2,
        'max_replicas' => 10,
        'target_cpu_utilization_percentage' => 65,
    ]);

    expect($resources)->toHaveCount(4);
    expect(array_column($resources, 'kind'))->toBe(['Deployment', 'Service', 'Ingress', 'HorizontalPodAutoscaler']);

    $deployment = $resources[0];
    $container = $deployment['spec']['template']['spec']['containers'][0];

    expect($deployment['metadata']['name'])->toBe('customer-api-ckv4a1b2');
    expect($deployment['metadata']['namespace'])->toBe('coolify-production');
    expect($container['image'])->toBe('registry.example.com/customer/api:2026.05.18');
    expect($container['ports'][0]['containerPort'])->toBe(8080);
    expect($container['readinessProbe']['httpGet']['path'])->toBe('/health');
    expect($container['resources']['limits'])->toBe([
        'memory' => '512Mi',
        'cpu' => '500m',
    ]);

    $service = $resources[1];
    expect($service['spec']['ports'][0])->toMatchArray([
        'port' => 80,
        'targetPort' => 8080,
    ]);

    $ingress = $resources[2];
    expect($ingress['spec']['ingressClassName'])->toBe('nginx');
    expect($ingress['spec']['rules'][0]['host'])->toBe('api.example.com');

    $hpa = $resources[3];
    expect($hpa['spec']['minReplicas'])->toBe(2);
    expect($hpa['spec']['maxReplicas'])->toBe(10);
    expect($hpa['spec']['metrics'][0]['resource']['target']['averageUtilization'])->toBe(65);
});

it('omits ingress and probes when application configuration does not need them', function () {
    $generator = new KubernetesApplicationManifestGenerator;

    $resources = $generator->generate(kubernetesApplication([
        'fqdn' => null,
        'health_check_enabled' => false,
    ]));

    expect(array_column($resources, 'kind'))->toBe(['Deployment', 'Service']);
    expect($resources[0]['spec']['template']['spec']['containers'][0])
        ->not->toHaveKeys(['readinessProbe', 'livenessProbe']);
});

it('adds runtime environment variables through an opaque secret', function () {
    $generator = new KubernetesApplicationManifestGenerator;

    $resources = $generator->generate(kubernetesApplication(), [
        'image' => 'registry.example.com/customer/api@sha256:abc123',
        'deployment_uuid' => 'deployment-123',
        'environment' => [
            'APP_ENV' => 'production',
            'INVALID-KEY' => 'ignored',
        ],
    ]);

    expect(array_column($resources, 'kind'))->toBe(['Secret', 'Deployment', 'Service', 'Ingress']);
    expect($resources[0]['data'])->toBe([
        'APP_ENV' => base64_encode('production'),
    ]);
    expect($resources[1]['spec']['template']['spec']['containers'][0]['image'])
        ->toBe('registry.example.com/customer/api@sha256:abc123');
    expect($resources[1]['spec']['template']['spec']['containers'][0]['envFrom'][0]['secretRef']['name'])
        ->toBe('customer-api-ckv4a1b2-env');
    expect($resources[1]['spec']['template']['metadata']['annotations']['coolify.io/deployment-uuid'])
        ->toBe('deployment-123');
});

it('throws when no registry image is configured', function () {
    $generator = new KubernetesApplicationManifestGenerator;

    expect(fn () => $generator->generate(kubernetesApplication([
        'docker_registry_image_name' => null,
    ])))->toThrow(InvalidArgumentException::class, 'docker_registry_image_name');
});

it('exports yaml accepted by kubectl client dry run', function () {
    $kubectl = trim((string) shell_exec('command -v kubectl 2>/dev/null'));

    if ($kubectl === '') {
        $this->markTestSkipped('kubectl is not installed.');
    }

    $generator = new KubernetesApplicationManifestGenerator;
    $yaml = $generator->toYaml(kubernetesApplication(), [
        'namespace' => 'default',
        'autoscaling' => true,
    ]);

    foreach (explode("---\n", $yaml) as $document) {
        expect(Yaml::parse($document))->toBeArray();
    }

    $path = tempnam(sys_get_temp_dir(), 'coolify-k8s-').'.yaml';
    file_put_contents($path, $yaml);

    $output = [];
    $exitCode = 0;
    exec(escapeshellcmd($kubectl).' apply --dry-run=client --validate=false -f '.escapeshellarg($path).' 2>&1', $output, $exitCode);
    unlink($path);

    expect($exitCode)->toBe(0, implode("\n", $output));
});
