<?php

use App\Services\Kubernetes\KubernetesApplicationStatusResolver;

function kubernetesDeploymentStatus(array $spec = [], array $status = []): string
{
    return json_encode([
        'spec' => array_merge(['replicas' => 2], $spec),
        'status' => array_merge([
            'availableReplicas' => 2,
            'readyReplicas' => 2,
        ], $status),
    ], JSON_THROW_ON_ERROR);
}

function kubernetesPodsStatus(array $items): string
{
    return json_encode(['items' => $items], JSON_THROW_ON_ERROR);
}

function kubernetesPod(string $phase = 'Running', bool $ready = true, ?string $waitingReason = null): array
{
    return [
        'status' => [
            'phase' => $phase,
            'containerStatuses' => [
                [
                    'name' => 'application',
                    'ready' => $ready,
                    'state' => $waitingReason ? [
                        'waiting' => ['reason' => $waitingReason],
                    ] : [
                        'running' => [],
                    ],
                ],
            ],
        ],
    ];
}

it('resolves healthy kubernetes deployments as running', function () {
    $status = (new KubernetesApplicationStatusResolver)->resolve(
        kubernetesDeploymentStatus(),
        kubernetesPodsStatus([
            kubernetesPod(),
            kubernetesPod(),
        ])
    );

    expect($status)->toBe('running:healthy');
});

it('resolves partial availability as degraded', function () {
    $status = (new KubernetesApplicationStatusResolver)->resolve(
        kubernetesDeploymentStatus(status: [
            'availableReplicas' => 1,
            'readyReplicas' => 1,
        ]),
        kubernetesPodsStatus([
            kubernetesPod(),
            kubernetesPod(ready: false),
        ])
    );

    expect($status)->toBe('degraded:unhealthy');
});

it('resolves pending pods without availability as starting', function () {
    $status = (new KubernetesApplicationStatusResolver)->resolve(
        kubernetesDeploymentStatus(status: [
            'availableReplicas' => 0,
            'readyReplicas' => 0,
        ]),
        kubernetesPodsStatus([
            kubernetesPod('Pending', false),
        ])
    );

    expect($status)->toBe('starting:unhealthy');
});

it('resolves crash loops without availability as unhealthy exited', function () {
    $status = (new KubernetesApplicationStatusResolver)->resolve(
        kubernetesDeploymentStatus(status: [
            'availableReplicas' => 0,
            'readyReplicas' => 0,
        ]),
        kubernetesPodsStatus([
            kubernetesPod('Running', false, 'CrashLoopBackOff'),
        ])
    );

    expect($status)->toBe('exited:unhealthy');
});

it('resolves zero desired replicas as exited', function () {
    $status = (new KubernetesApplicationStatusResolver)->resolve(
        kubernetesDeploymentStatus(['replicas' => 0]),
        kubernetesPodsStatus([])
    );

    expect($status)->toBe('exited');
});
