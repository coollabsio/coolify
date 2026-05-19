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
        'service_account_name' => 'coolify-workload',
        'create_service_account' => true,
        'image_pull_secrets' => 'regcred',
        'node_selector' => 'workload=apps',
        'tolerations' => "- key: dedicated\n  operator: Equal\n  value: apps\n  effect: NoSchedule",
        'ingress_annotations' => 'traefik.ingress.kubernetes.io/router.entrypoints=websecure',
        'pod_disruption_budget_enabled' => true,
        'pod_disruption_budget_min_available' => '50%',
        'environment' => [
            'SHARED' => 'true',
        ],
    ]);

    expect(array_column($resources, 'kind'))->toBe([
        'ServiceAccount',
        'PersistentVolumeClaim',
        'Secret',
        'Deployment',
        'Service',
        'Ingress',
        'HorizontalPodAutoscaler',
        'PodDisruptionBudget',
        'Secret',
        'Deployment',
        'Service',
        'HorizontalPodAutoscaler',
        'PodDisruptionBudget',
    ]);

    $apiDeployment = collect($resources)->where('kind', 'Deployment')->first();
    $apiIngress = collect($resources)->firstWhere('kind', 'Ingress');
    $pdb = collect($resources)->firstWhere('kind', 'PodDisruptionBudget');
    $serviceAccount = collect($resources)->firstWhere('kind', 'ServiceAccount');
    $workerDeployment = collect($resources)->where('kind', 'Deployment')->last();

    expect($apiDeployment['metadata']['name'])->toBe('customer-stack-api-ckv4a1b2');
    expect($apiDeployment['spec']['template']['spec']['containers'][0]['image'])->toBe('ghcr.io/example/api:1.0.0');
    expect($apiDeployment['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'])->toBe(3000);
    expect($apiDeployment['spec']['template']['spec']['containers'][0]['volumeMounts'][0]['mountPath'])->toBe('/var/lib/api');
    expect($apiDeployment['spec']['template']['spec']['serviceAccountName'])->toBe('coolify-workload');
    expect($apiDeployment['spec']['template']['spec']['imagePullSecrets'][0]['name'])->toBe('regcred');
    expect($apiDeployment['spec']['template']['spec']['nodeSelector'])->toBe(['workload' => 'apps']);
    expect($apiDeployment['spec']['template']['spec']['tolerations'][0]['key'])->toBe('dedicated');

    expect(collect($resources)->where('kind', 'Secret')->first()['data'])->toMatchArray([
        'APP_ENV' => base64_encode('production'),
        'SHARED' => base64_encode('true'),
    ]);
    expect($apiIngress['spec']['rules'][0]['host'])->toBe('api.example.com');
    expect($apiIngress['metadata']['annotations']['traefik.ingress.kubernetes.io/router.entrypoints'])->toBe('websecure');
    expect($pdb['spec']['minAvailable'])->toBe('50%');
    expect($serviceAccount['imagePullSecrets'][0]['name'])->toBe('regcred');
    expect($workerDeployment['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'])->toBe(9000);
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
