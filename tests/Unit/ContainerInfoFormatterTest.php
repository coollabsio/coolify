<?php

use App\Support\ContainerInfoFormatter;

it('formats Docker inspect container details for display', function () {
    $formatted = ContainerInfoFormatter::fromDockerInspect([
        'Id' => '18c7f9a6b5d4c3e2f10987654321abcdef',
        'Name' => '/coolify-demo',
        'Image' => 'sha256:abcdef1234567890',
        'Created' => '2026-05-11T03:00:00.000000000Z',
        'RestartCount' => 2,
        'Config' => [
            'Image' => 'nginx:latest',
        ],
        'State' => [
            'Status' => 'running',
            'StartedAt' => '2026-05-11T03:01:00.000000000Z',
            'FinishedAt' => '0001-01-01T00:00:00Z',
        ],
        'NetworkSettings' => [
            'Networks' => [
                'coolify' => [
                    'IPAddress' => '172.18.0.5',
                    'GlobalIPv6Address' => 'fd00::5',
                    'MacAddress' => '02:42:ac:12:00:05',
                    'NetworkID' => 'network-123',
                ],
            ],
        ],
    ]);

    expect($formatted['id_short'])->toBe('18c7f9a6b5d4')
        ->and($formatted['name'])->toBe('coolify-demo')
        ->and($formatted['image'])->toBe('nginx:latest')
        ->and($formatted['image_id'])->toBe('sha256:abcdef1234567890')
        ->and($formatted['status'])->toBe('running')
        ->and($formatted['restart_count'])->toBe(2)
        ->and($formatted['finished_at'])->toBeNull()
        ->and($formatted['networks'])->toHaveCount(1)
        ->and($formatted['networks'][0])->toMatchArray([
            'name' => 'coolify',
            'ipv4' => '172.18.0.5',
            'ipv6' => 'fd00::5',
            'mac_address' => '02:42:ac:12:00:05',
            'network_id' => 'network-123',
        ]);
});

it('handles missing Docker network settings', function () {
    $formatted = ContainerInfoFormatter::fromDockerInspect([
        'Id' => 'abc',
        'Name' => '/without-network',
    ]);

    expect($formatted['networks'])->toBe([])
        ->and($formatted['name'])->toBe('without-network');
});
