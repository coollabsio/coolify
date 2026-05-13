<?php

use App\Support\ContainerInfoPresenter;

it('formats docker inspect payload into container info metadata', function () {
    $inspect = [
        'Id' => 'abcdef1234567890',
        'Name' => '/coolify-demo-container',
        'Config' => [
            'Image' => 'ghcr.io/example/demo:latest',
            'Hostname' => 'coolify-demo-container',
        ],
        'Image' => 'sha256:1234abcd',
        'RepoDigests' => [
            'ghcr.io/example/demo@sha256:beefcafe',
        ],
        'Created' => '2026-05-13T04:00:00Z',
        'State' => [
            'Status' => 'running',
            'StartedAt' => '2026-05-13T04:05:00Z',
        ],
        'RestartCount' => 3,
        'NetworkSettings' => [
            'Networks' => [
                'coolify' => [
                    'IPAddress' => '172.18.0.9',
                    'GlobalIPv6Address' => 'fd00::9',
                    'MacAddress' => '02:42:ac:12:00:09',
                    'Gateway' => '172.18.0.1',
                ],
            ],
        ],
    ];

    $result = ContainerInfoPresenter::fromInspect($inspect, 'fallback-container');

    expect($result['container_id'])->toBe('abcdef1234567890')
        ->and($result['container_name'])->toBe('coolify-demo-container')
        ->and($result['image_reference'])->toBe('ghcr.io/example/demo:latest')
        ->and($result['image_hash'])->toBe('sha256:1234abcd')
        ->and($result['image_digest'])->toBe('ghcr.io/example/demo@sha256:beefcafe')
        ->and($result['hostname'])->toBe('coolify-demo-container')
        ->and($result['status'])->toBe('running')
        ->and($result['restart_count'])->toBe(3)
        ->and($result['created_at']['iso'])->toBe('2026-05-13T04:00:00+00:00')
        ->and($result['started_at']['iso'])->toBe('2026-05-13T04:05:00+00:00')
        ->and($result['networks'])->toHaveCount(1)
        ->and($result['networks'][0]['name'])->toBe('coolify')
        ->and($result['networks'][0]['ipv4'])->toBe('172.18.0.9')
        ->and($result['networks'][0]['ipv6'])->toBe('fd00::9')
        ->and($result['networks'][0]['mac'])->toBe('02:42:ac:12:00:09')
        ->and($result['networks'][0]['gateway'])->toBe('172.18.0.1');
});

it('treats missing docker dates as unavailable', function () {
    $result = ContainerInfoPresenter::fromInspect([
        'Name' => '/fallback-container',
        'State' => [
            'Status' => 'created',
            'StartedAt' => '0001-01-01T00:00:00Z',
        ],
        'Created' => null,
        'NetworkSettings' => [
            'Networks' => [],
        ],
    ], 'fallback-container');

    expect($result['container_name'])->toBe('fallback-container')
        ->and($result['created_at'])->toBeNull()
        ->and($result['started_at'])->toBeNull()
        ->and($result['networks'])->toBeArray()
        ->and($result['networks'])->toHaveCount(0);
});
