<?php

use App\Services\Kubernetes\KubernetesResourceStatusParser;

it('parses coolify managed kubernetes resource status summaries', function () {
    $json = json_encode([
        'items' => [
            [
                'kind' => 'Deployment',
                'metadata' => [
                    'name' => 'api',
                    'namespace' => 'production',
                    'creationTimestamp' => now()->subMinutes(5)->toIso8601String(),
                    'labels' => ['coolify.io/application-uuid' => 'app-1'],
                ],
                'spec' => ['replicas' => 2],
                'status' => ['readyReplicas' => 2, 'availableReplicas' => 2],
            ],
            [
                'kind' => 'Service',
                'metadata' => ['name' => 'api', 'namespace' => 'production'],
                'spec' => [
                    'type' => 'ClusterIP',
                    'ports' => [
                        ['port' => 80, 'targetPort' => 3000],
                    ],
                ],
            ],
            [
                'kind' => 'HorizontalPodAutoscaler',
                'metadata' => ['name' => 'api', 'namespace' => 'production'],
                'status' => ['currentReplicas' => 1, 'desiredReplicas' => 2],
            ],
            [
                'kind' => 'PersistentVolumeClaim',
                'metadata' => ['name' => 'api-data', 'namespace' => 'production'],
                'spec' => ['storageClassName' => 'fast'],
                'status' => ['phase' => 'Bound', 'capacity' => ['storage' => '1Gi']],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $resources = (new KubernetesResourceStatusParser)->parse($json);

    expect($resources)->toHaveCount(4)
        ->and($resources[0])->toMatchArray([
            'kind' => 'Deployment',
            'name' => 'api',
            'namespace' => 'production',
            'status' => 'Ready',
            'detail' => 'ready 2/2, available 2',
            'application_uuid' => 'app-1',
        ])
        ->and($resources[1])->toMatchArray([
            'kind' => 'HorizontalPodAutoscaler',
            'status' => 'Scaling',
            'detail' => 'replicas 1/2',
        ])
        ->and($resources[2])->toMatchArray([
            'kind' => 'PersistentVolumeClaim',
            'status' => 'Bound',
            'detail' => '1Gi fast',
        ])
        ->and($resources[3])->toMatchArray([
            'kind' => 'Service',
            'status' => 'ClusterIP',
            'detail' => '80:3000',
        ]);
});

it('marks stopped deployments and pending ingress resources', function () {
    $json = json_encode([
        'items' => [
            [
                'kind' => 'Ingress',
                'metadata' => ['name' => 'api', 'namespace' => 'production'],
                'spec' => ['rules' => [['host' => 'api.example.com']]],
                'status' => [],
            ],
            [
                'kind' => 'Deployment',
                'metadata' => ['name' => 'worker', 'namespace' => 'production'],
                'spec' => ['replicas' => 0],
                'status' => [],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $resources = (new KubernetesResourceStatusParser)->parse($json);

    expect($resources[0])->toMatchArray([
        'kind' => 'Deployment',
        'status' => 'Stopped',
    ])
        ->and($resources[1])->toMatchArray([
            'kind' => 'Ingress',
            'status' => 'Pending',
            'detail' => 'api.example.com',
        ]);
});
