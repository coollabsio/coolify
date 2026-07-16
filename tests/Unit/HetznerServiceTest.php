<?php

use App\Services\HetznerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

it('getServers returns list of servers from Hetzner API', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'name' => 'test-server-1',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '123.45.67.89'],
                        'ipv6' => ['ip' => '2a01:4f8::/64'],
                    ],
                ],
                [
                    'id' => 67890,
                    'name' => 'test-server-2',
                    'status' => 'off',
                    'public_net' => [
                        'ipv4' => ['ip' => '98.76.54.32'],
                        'ipv6' => ['ip' => '2a01:4f9::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $servers = $service->getServers();

    expect($servers)->toBeArray()
        ->and(count($servers))->toBe(2)
        ->and($servers[0]['id'])->toBe(12345)
        ->and($servers[1]['id'])->toBe(67890);
});

it('findServerByIp returns matching server by IPv4', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'name' => 'test-server',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '123.45.67.89'],
                        'ipv6' => ['ip' => '2a01:4f8::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('123.45.67.89');

    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe(12345)
        ->and($result['name'])->toBe('test-server');
});

it('prefers the public IPv4 address over the IPv6 allocation', function () {
    $service = new HetznerService('fake-token');

    $result = $service->getPublicIpAddress([
        'public_net' => [
            'ipv4' => ['ip' => '123.45.67.89'],
            'ipv6' => ['ip' => '2a01:4f8:c17:4c00::/64'],
        ],
    ]);

    expect($result)->toBe('123.45.67.89');
});

it('uses the first host address from an IPv6 allocation', function () {
    $service = new HetznerService('fake-token');

    $result = $service->getPublicIpAddress([
        'public_net' => [
            'ipv4' => ['ip' => null],
            'ipv6' => ['ip' => '2a01:4f8:c17:4c00:dead:beef::/64'],
        ],
    ]);

    expect($result)->toBe('2a01:4f8:c17:4c00::1');
});

it('findServerByIp matches the first host in an IPv6 allocation', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'name' => 'ipv6-server',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => null],
                        'ipv6' => ['ip' => '2a01:4f8:c17:4c00::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('2a01:4f8:c17:4c00::1');

    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe(12345);
});

it('findServerByIp matches any host in an IPv6 allocation but not an adjacent allocation', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'name' => 'ipv6-server',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => null],
                        'ipv6' => ['ip' => '2a01:4f8:c17:4c00::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $sameAllocation = $service->findServerByIp('2a01:4f8:c17:4c00::42');
    $adjacentAllocation = $service->findServerByIp('2a01:4f8:c17:4c01::1');

    expect($sameAllocation)->not->toBeNull()
        ->and($sameAllocation['id'])->toBe(12345)
        ->and($adjacentAllocation)->toBeNull();
});

it('findServerByIp rejects invalid IPv6 addresses and allocation prefixes', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'public_net' => [
                        'ipv4' => ['ip' => null],
                        'ipv6' => ['ip' => '2a01:4f8:c17:4c00::/64'],
                    ],
                ],
                [
                    'id' => 67890,
                    'public_net' => [
                        'ipv4' => ['ip' => null],
                        'ipv6' => ['ip' => '2a01:4f8:c17:4c01::/129'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');

    expect($service->findServerByIp('not-an-ip'))->toBeNull()
        ->and($service->findServerByIp('2a01:4f8:c17:4c01::1'))->toBeNull();
});

it('findServerByIp returns null when no match', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'name' => 'test-server',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '123.45.67.89'],
                        'ipv6' => ['ip' => '2a01:4f8::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('1.2.3.4');

    expect($result)->toBeNull();
});

it('findServerByIp returns null when server list is empty', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('123.45.67.89');

    expect($result)->toBeNull();
});

it('findServerByIp matches correct server among multiple', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 11111,
                    'name' => 'server-a',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '10.0.0.1'],
                        'ipv6' => ['ip' => '2a01:4f8::/64'],
                    ],
                ],
                [
                    'id' => 22222,
                    'name' => 'server-b',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '10.0.0.2'],
                        'ipv6' => ['ip' => '2a01:4f9::/64'],
                    ],
                ],
                [
                    'id' => 33333,
                    'name' => 'server-c',
                    'status' => 'off',
                    'public_net' => [
                        'ipv4' => ['ip' => '10.0.0.3'],
                        'ipv6' => ['ip' => '2a01:4fa::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('10.0.0.2');

    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe(22222)
        ->and($result['name'])->toBe('server-b');
});
