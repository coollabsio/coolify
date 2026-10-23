<?php

use App\Support\RuntimeLogTargets;

function runtimeLogServer(string $uuid, string $name, array $containers): array
{
    return [
        'uuid' => $uuid,
        'name' => $name,
        'containers' => $containers,
    ];
}

function runtimeLogContainer(string $name, string $labels, string $state = 'running', string $id = ''): array
{
    return [
        'ID' => $id !== '' ? $id : $name,
        'Names' => $name,
        'State' => $state,
        'Labels' => $labels,
    ];
}

function runtimeLogTargets(array $servers, string $deployment = '0', string $service = '', bool $groupDeployments = true): array
{
    return RuntimeLogTargets::select($servers, $deployment, $service, $groupDeployments);
}

it('orders production ahead of newer previews and uses the top bar pills', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app-pr-7', 'coolify.pullRequestId=7,com.docker.compose.service=web', 'exited'),
            runtimeLogContainer('app', 'coolify.pullRequestId=0,com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-42', 'coolify.pullRequestId=42,com.docker.compose.service=web'),
        ]),
    ]);

    expect($targets['deployments'])->toBe([
        ['value' => '0', 'label' => 'Production', 'dot' => 'success'],
        ['value' => '42', 'label' => 'PR #42', 'dot' => 'success'],
        ['value' => '7', 'label' => 'PR #7', 'dot' => 'error', 'state' => 'Stopped'],
    ])
        ->and($targets['deployment'])->toBe('0')
        ->and($targets['service'])->toBe('')
        ->and($targets['sources'][0]['container'])->toBe('app')
        ->and($targets)->not->toHaveKey('servers');
});

it('treats a missing pull request label as production', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app', 'com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-3', 'coolify.pullRequestId=3,com.docker.compose.service=web'),
        ]),
    ]);

    expect($targets['deployments'][0]['label'])->toBe('Production')
        ->and($targets['sources'][0]['container'])->toBe('app');
});

it('uses the preview from the query when that deployment exists', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app', 'coolify.pullRequestId=0,com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-12', 'coolify.pullRequestId=12,com.docker.compose.service=web'),
        ]),
    ], '12');

    expect($targets['deployment'])->toBe('12')
        ->and($targets['sources'][0]['container'])->toBe('app-pr-12');
});

it('falls back to production when the requested preview is gone', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app', 'coolify.pullRequestId=0,com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-7', 'coolify.pullRequestId=7,com.docker.compose.service=web'),
        ]),
    ], '99');

    expect($targets['deployment'])->toBe('0')
        ->and($targets['sources'][0]['container'])->toBe('app');
});

it('falls back to the newest preview when production has no container', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app-pr-3', 'coolify.pullRequestId=3,com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-12', 'coolify.pullRequestId=12,com.docker.compose.service=web'),
        ]),
    ]);

    expect($targets['deployment'])->toBe('12')
        ->and($targets['sources'][0]['container'])->toBe('app-pr-12');
});

it('names services from the compose label, then coolify.name, then the container headline', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('compose-name', 'com.docker.compose.service=api,coolify.name=ignored'),
            runtimeLogContainer('labeled-name', 'coolify.name=worker'),
            runtimeLogContainer('plain-container-1', ''),
        ]),
    ], '0', '', false);

    expect(array_column($targets['services'], 'label'))->toBe(['api', 'Plain Container', 'worker'])
        ->and($targets['service'])->toBe('api')
        ->and($targets['sources'][0]['container'])->toBe('compose-name');
});

it('gives each service one option and colors it from the container states', function (array $states, string $dot, ?string $description, ?string $stateLabel) {
    $containers = [];
    foreach ($states as $index => $state) {
        $containers[] = runtimeLogContainer('web-'.$index, 'com.docker.compose.service=web', $state);
    }

    $option = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', $containers),
    ])['services'][0];

    expect($option['label'])->toBe('web')
        ->and($option['value'])->toBe('web')
        ->and($option['dot'])->toBe($dot)
        ->and($option['description'] ?? null)->toBe($description)
        ->and($option['state'] ?? null)->toBe($stateLabel);
})->with([
    'running' => [['running'], 'success', null, null],
    'starting' => [['starting'], 'warning', null, 'Starting'],
    'restarting' => [['running', 'restarting'], 'warning', '2 containers', 'Restarting'],
    'mixed' => [['running', 'exited'], 'warning', '2 containers', 'Degraded'],
    'stopped' => [['exited', 'dead'], 'error', '2 containers', 'Stopped'],
]);

it('defaults to the first running service and keeps the requested service key', function () {
    $servers = [
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('api', 'com.docker.compose.service=api', 'exited'),
            runtimeLogContainer('web-old', 'com.docker.compose.service=web', 'exited'),
            runtimeLogContainer('web-new', 'com.docker.compose.service=web', 'running'),
            runtimeLogContainer('worker', 'com.docker.compose.service=worker', 'running'),
        ]),
    ];

    $targets = runtimeLogTargets($servers);

    expect(array_column($targets['services'], 'label'))->toBe(['api', 'web', 'worker'])
        ->and($targets['services'][0]['dot'])->toBe('error')
        ->and($targets['services'][0]['state'])->toBe('Stopped')
        ->and($targets['services'][1]['dot'])->toBe('warning')
        ->and($targets['services'][1]['state'])->toBe('Degraded')
        ->and($targets['services'][1]['description'])->toBe('2 containers')
        ->and($targets['services'][2]['state'] ?? null)->toBeNull()
        ->and($targets['service'])->toBe('web')
        ->and(array_column($targets['sources'], 'container'))->toBe(['web-new', 'web-old']);

    $requested = runtimeLogTargets($servers, '0', 'worker');

    expect($requested['service'])->toBe('worker')
        ->and($requested['sources'][0]['container'])->toBe('worker');
});

it('merges a service across servers and a rollout duplicate', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Frankfurt', [
            runtimeLogContainer('web-new', 'com.docker.compose.service=web', 'running'),
            runtimeLogContainer('web-old', 'com.docker.compose.service=web', 'exited'),
            runtimeLogContainer('api', 'com.docker.compose.service=api', 'running'),
        ]),
        runtimeLogServer('edge', 'Helsinki', [
            runtimeLogContainer('web-edge', 'com.docker.compose.service=web', 'running'),
        ]),
    ], '0', 'web');

    expect($targets)->not->toHaveKey('servers')
        ->and($targets['sources'])->toBe([
            ['server' => 'primary', 'container' => 'web-new', 'label' => 'Frankfurt web-new'],
            ['server' => 'primary', 'container' => 'web-old', 'label' => 'Frankfurt web-old'],
            ['server' => 'edge', 'container' => 'web-edge', 'label' => 'Helsinki'],
        ])
        ->and($targets['streamKey'])->toBe('primary:web-new|primary:web-old|edge:web-edge');
});

it('opens a preview that exists on only one server', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('primary-app', 'coolify.pullRequestId=0'),
        ]),
        runtimeLogServer('edge', 'Edge', [
            runtimeLogContainer('edge-pr-8', 'coolify.pullRequestId=8'),
        ]),
    ], '8');

    expect($targets['deployment'])->toBe('8')
        ->and($targets['sources'])->toBe([
            ['server' => 'edge', 'container' => 'edge-pr-8', 'label' => 'edge-pr-8'],
        ]);
});

it('does not split one-click services into preview deployments', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app-service', 'coolify.pullRequestId=4,com.docker.compose.service=app'),
            runtimeLogContainer('db-service', 'coolify.pullRequestId=4,com.docker.compose.service=db'),
        ]),
    ], '4', '', false);

    expect($targets['deployments'])->toBe([])
        ->and($targets['deployment'])->toBe('0')
        ->and(array_column($targets['services'], 'label'))->toBe(['app', 'db']);
});

it('reads labels given as an array', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            [
                'ID' => 'app-id',
                'Names' => 'app',
                'State' => 'running',
                'Labels' => [
                    'coolify.pullRequestId' => '0',
                    'com.docker.compose.service' => 'web',
                ],
            ],
        ]),
    ]);

    expect($targets['sources'][0]['container'])->toBe('app')
        ->and($targets['services'][0]['label'])->toBe('web');
});

it('drops containers whose names cannot be passed to docker logs', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('valid-name', 'com.docker.compose.service=web'),
            runtimeLogContainer('bad name', 'com.docker.compose.service=api'),
        ]),
    ]);

    expect(array_column($targets['services'], 'value'))->toBe(['web']);
});

it('returns an empty selection when nothing is running', function () {
    $targets = runtimeLogTargets([
        runtimeLogServer('primary', 'Primary', []),
    ], '12', 'web');

    expect($targets['sources'])->toBe([])
        ->and($targets['services'])->toBe([])
        ->and($targets['streamKey'])->toBe('');
});
