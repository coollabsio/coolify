<?php

use App\Models\Application;
use App\Services\Kubernetes\KubernetesComposeManifestGenerator;

function kubernetesComposeApplication(array $attributes = []): Application
{
    return new Application(array_merge([
        'name' => 'Customer Stack',
        'uuid' => 'ckv4a1b2c3d4e5f6g7h8i9j0',
        'docker_compose_domains' => json_encode([
            'api' => ['domain' => 'https://api.example.com'],
        ], JSON_THROW_ON_ERROR),
    ], $attributes));
}

it('generates kubernetes resources for image based compose services', function () {
    $generator = new KubernetesComposeManifestGenerator;
    $compose = [
        'services' => [
            'api' => [
                'image' => 'ghcr.io/example/api:1.0.0',
                'ports' => ['8080:3000'],
                'environment' => [
                    'APP_ENV' => 'production',
                ],
                'volumes' => [
                    'api-data:/var/lib/api',
                ],
            ],
            'worker' => [
                'image' => 'ghcr.io/example/worker:1.0.0',
                'expose' => ['9000'],
            ],
        ],
        'volumes' => [
            'api-data' => [],
        ],
    ];

    $resources = $generator->generate(kubernetesComposeApplication(), $compose, [
        'namespace' => 'production',
        'service_type' => 'ClusterIP',
        'storage_size' => '5Gi',
        'autoscaling' => true,
        'min_replicas' => 2,
        'max_replicas' => 4,
        'environment' => [
            'SHARED' => 'true',
        ],
    ]);

    expect(array_column($resources, 'kind'))->toBe([
        'PersistentVolumeClaim',
        'Secret',
        'Deployment',
        'Service',
        'Ingress',
        'HorizontalPodAutoscaler',
        'Secret',
        'Deployment',
        'Service',
        'HorizontalPodAutoscaler',
    ]);

    $apiDeployment = $resources[2];
    expect($apiDeployment['metadata']['name'])->toBe('customer-stack-api-ckv4a1b2');
    expect($apiDeployment['spec']['template']['spec']['containers'][0]['image'])->toBe('ghcr.io/example/api:1.0.0');
    expect($apiDeployment['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'])->toBe(3000);
    expect($apiDeployment['spec']['template']['spec']['containers'][0]['volumeMounts'][0]['mountPath'])->toBe('/var/lib/api');

    expect($resources[1]['data'])->toMatchArray([
        'APP_ENV' => base64_encode('production'),
        'SHARED' => base64_encode('true'),
    ]);
    expect($resources[4]['spec']['rules'][0]['host'])->toBe('api.example.com');
    expect($resources[7]['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'])->toBe(9000);
});

it('rejects compose services that require local builds', function () {
    $generator = new KubernetesComposeManifestGenerator;

    expect(fn () => $generator->generate(kubernetesComposeApplication(), [
        'services' => [
            'api' => [
                'build' => '.',
            ],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'uses build');
});

it('rejects bind mounts for kubernetes compose deployments', function () {
    $generator = new KubernetesComposeManifestGenerator;

    expect(fn () => $generator->generate(kubernetesComposeApplication(), [
        'services' => [
            'api' => [
                'image' => 'ghcr.io/example/api:1.0.0',
                'volumes' => [
                    './data:/var/lib/api',
                ],
            ],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'bind mount');
});
