<?php

use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('fetches paginated regions from DigitalOcean', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/regions?page=1&per_page=50' => Http::response([
            'regions' => [
                ['slug' => 'nyc1', 'name' => 'New York 1', 'available' => true],
            ],
            'links' => ['pages' => ['next' => 'https://api.digitalocean.com/v2/regions?page=2&per_page=50']],
        ]),
        'https://api.digitalocean.com/v2/regions?page=2&per_page=50' => Http::response([
            'regions' => [
                ['slug' => 'ams3', 'name' => 'Amsterdam 3', 'available' => true],
            ],
            'links' => ['pages' => []],
        ]),
    ]);

    $regions = (new DigitalOceanService('test-token'))->getRegions();

    expect($regions)->toHaveCount(2)
        ->and($regions[0]['slug'])->toBe('nyc1')
        ->and($regions[1]['slug'])->toBe('ams3');
});

it('creates a droplet with the selected SSH keys and options', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets' => Http::response([
            'droplet' => [
                'id' => 987,
                'name' => 'coolify-test',
                'status' => 'new',
                'networks' => [
                    'v4' => [
                        ['ip_address' => '203.0.113.10', 'type' => 'public'],
                    ],
                ],
            ],
        ], 202),
    ]);

    $droplet = (new DigitalOceanService('test-token'))->createDroplet([
        'name' => 'coolify-test',
        'region' => 'nyc1',
        'size' => 's-1vcpu-1gb',
        'image' => 'ubuntu-24-04-x64',
        'ssh_keys' => [123],
        'ipv6' => true,
        'monitoring' => true,
        'user_data' => '#cloud-config',
    ]);

    expect($droplet['id'])->toBe(987);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.digitalocean.com/v2/droplets'
        && $request['ssh_keys'] === [123]
        && $request['ipv6'] === true
        && $request['monitoring'] === true
        && $request['user_data'] === '#cloud-config');
});

it('extracts a public IPv4 address before falling back to IPv6', function () {
    $droplet = [
        'networks' => [
            'v4' => [
                ['ip_address' => '10.0.0.2', 'type' => 'private'],
                ['ip_address' => '203.0.113.10', 'type' => 'public'],
            ],
            'v6' => [
                ['ip_address' => '2001:db8::10', 'type' => 'public'],
            ],
        ],
    ];

    $service = new DigitalOceanService('test-token');

    expect($service->getPublicIpAddress($droplet, true, true))->toBe('203.0.113.10')
        ->and($service->getPublicIpAddress($droplet, false, true))->toBe('2001:db8::10');
});

it('waits for DigitalOcean to assign a public IP', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response([
            'droplet' => [
                'id' => 987,
                'status' => 'active',
                'networks' => [
                    'v4' => [
                        ['ip_address' => '203.0.113.10', 'type' => 'public'],
                    ],
                ],
            ],
        ]),
    ]);

    $service = new DigitalOceanService('test-token');
    $droplet = $service->waitForPublicIp([
        'id' => 987,
        'status' => 'new',
        'networks' => ['v4' => []],
    ], sleepMilliseconds: 0);

    expect($service->getPublicIpAddress($droplet))->toBe('203.0.113.10');
});

it('keeps polling when DigitalOcean assigns a public IP slowly', function () {
    $polls = 0;

    Http::fake(function () use (&$polls) {
        $polls++;

        return Http::response([
            'droplet' => [
                'id' => 987,
                'status' => $polls < 10 ? 'new' : 'active',
                'networks' => [
                    'v4' => $polls < 10 ? [] : [
                        ['ip_address' => '203.0.113.10', 'type' => 'public'],
                    ],
                ],
            ],
        ]);
    });

    $service = new DigitalOceanService('test-token');
    $droplet = $service->waitForPublicIp([
        'id' => 987,
        'status' => 'new',
        'networks' => ['v4' => []],
    ], sleepMilliseconds: 0);

    expect($service->getPublicIpAddress($droplet))->toBe('203.0.113.10')
        ->and($polls)->toBe(10);
});

it('finds a droplet by public IP', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets*' => Http::response([
            'droplets' => [
                [
                    'id' => 987,
                    'networks' => [
                        'v4' => [
                            ['ip_address' => '203.0.113.10', 'type' => 'public'],
                        ],
                        'v6' => [
                            ['ip_address' => '2001:db8::10', 'type' => 'public'],
                        ],
                    ],
                ],
            ],
            'links' => ['pages' => []],
        ]),
    ]);

    $service = new DigitalOceanService('test-token');

    expect($service->findDropletByIp('203.0.113.10')['id'])->toBe(987)
        ->and($service->findDropletByIp('2001:db8::10')['id'])->toBe(987)
        ->and($service->findDropletByIp('198.51.100.1'))->toBeNull();
});

it('powers on and deletes a DigitalOcean droplet', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987/actions' => Http::response([
            'action' => ['id' => 111, 'type' => 'power_on'],
        ], 201),
        'https://api.digitalocean.com/v2/droplets/987' => Http::response(null, 204),
    ]);

    $service = new DigitalOceanService('test-token');

    expect($service->powerOnDroplet(987)['type'])->toBe('power_on');
    $service->deleteDroplet(987);

    Http::assertSentCount(2);
});
