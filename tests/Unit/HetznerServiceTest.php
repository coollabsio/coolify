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

it('findServerByIp matches an IPv6 server address inside the Hetzner network', function (string $ip) {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 11111,
                    'name' => 'other-server',
                    'public_net' => [
                        'ipv4' => ['ip' => '10.0.0.1'],
                        'ipv6' => ['ip' => '2a01:4f8:c016:aaaa::/64'],
                    ],
                ],
                [
                    'id' => 22222,
                    'name' => 'ipv6-server',
                    'public_net' => [
                        'ipv4' => null,
                        'ipv6' => ['ip' => '2a01:4f8:c016:bd23::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $result = (new HetznerService('fake-token'))->findServerByIp($ip);

    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe(22222);
})->with([
    'server address' => '2a01:4f8:c016:bd23::1',
    'long upper-case form' => '2A01:04F8:C016:BD23:0000:0000:0000:0001',
    'in brackets' => '[2a01:4f8:c016:bd23::1]',
]);

it('findServerByIp does not match an IPv6 address outside the Hetzner network', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 22222,
                    'public_net' => [
                        'ipv4' => ['ip' => '10.0.0.1'],
                        'ipv6' => ['ip' => '2a01:4f8:c016:bd23::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    expect((new HetznerService('fake-token'))->findServerByIp('2a01:4f8:c016:bd24::1'))->toBeNull()
        ->and((new HetznerService('fake-token'))->findServerByIp('10.0.0.2'))->toBeNull();
});
