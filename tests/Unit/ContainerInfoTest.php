<?php

use App\Support\ContainerInfo;

it('normalizes docker inspect data for display', function () {
    $inspect = [[
        'Id' => 'c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f',
        'Name' => '/web-service-uuid',
        'Config' => [
            'Image' => 'ghcr.io/example/app:1.2.3',
        ],
        'Created' => '2026-04-24T12:34:56.123456789Z',
        'State' => [
            'StartedAt' => '2026-04-24T12:35:10.987654321Z',
        ],
        'NetworkSettings' => [
            'Networks' => [
                'coolify' => [
                    'IPAddress' => '172.18.0.5',
                    'GlobalIPv6Address' => 'fd00::5',
                ],
                'other' => [
                    'IPAddress' => '172.19.0.7',
                    'GlobalIPv6Address' => '',
                ],
            ],
        ],
    ]];

    expect(ContainerInfo::fromDockerInspect($inspect))->toBe([
        'id' => 'c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f',
        'name' => 'web-service-uuid',
        'image' => 'ghcr.io/example/app:1.2.3',
        'created_at' => '2026-04-24T12:34:56.123456789Z',
        'started_at' => '2026-04-24T12:35:10.987654321Z',
        'ipv4_addresses' => ['172.18.0.5', '172.19.0.7'],
        'ipv6_addresses' => ['fd00::5'],
    ]);
});

it('builds a docker inspect command for applications', function () {
    expect(ContainerInfo::inspectCommandForApplication(42))
        ->toContain('coolify.applicationId=42')
        ->toContain('docker ps --filter')
        ->toContain('docker ps -a --filter')
        ->toContain('docker inspect');
});

it('builds a docker inspect command for service sub resources', function () {
    expect(ContainerInfo::inspectCommandForServiceSub(12, 'database', 99))
        ->toContain('coolify.serviceId=12')
        ->toContain('coolify.service.subType=database')
        ->toContain('coolify.service.subId=99')
        ->toContain('docker ps --filter')
        ->toContain('docker ps -a --filter')
        ->toContain('docker inspect');
});
