<?php

use App\Exceptions\RateLimitException;
use App\Services\HostingerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('fetches Hostinger provisioning options', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/data-centers' => Http::response([
            ['id' => 19, 'name' => 'nl-ams', 'city' => 'Amsterdam', 'location' => 'nl'],
        ]),
        'https://developers.hostinger.com/api/vps/v1/templates' => Http::response([
            ['id' => 1130, 'name' => 'Ubuntu 24.04 LTS'],
        ]),
        'https://developers.hostinger.com/api/billing/v1/catalog?category=VPS' => Http::response([
            [
                'id' => 'hostingercom-vps-kvm2',
                'name' => 'KVM 2',
                'category' => 'VPS',
                'prices' => [
                    [
                        'id' => 'hostingercom-vps-kvm2-usd-1m',
                        'currency' => 'USD',
                        'price' => 1799,
                        'first_period_price' => 899,
                        'period' => 1,
                        'period_unit' => 'month',
                    ],
                ],
            ],
        ]),
    ]);

    $service = new HostingerService('test-token');

    expect($service->getDataCenters()[0]['city'])->toBe('Amsterdam')
        ->and($service->getTemplates()[0]['name'])->toBe('Ubuntu 24.04 LTS')
        ->and($service->getCatalogItems()[0]['prices'][0]['price'])->toBe(1799);
});

it('purchases a Hostinger virtual machine with its setup options', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'order' => ['id' => 2957086, 'status' => 'completed'],
            'virtual_machine' => [
                'id' => 17923,
                'hostname' => 'coolify-test.example.com',
                'state' => 'creating',
                'ipv4' => [['address' => '203.0.113.10']],
            ],
        ]),
    ]);

    $virtualMachine = (new HostingerService('test-token'))->purchaseVirtualMachine([
        'item_id' => 'hostingercom-vps-kvm2-usd-1m',
        'setup' => [
            'data_center_id' => 19,
            'template_id' => 1130,
            'hostname' => 'coolify-test.example.com',
            'enable_backups' => true,
            'public_key' => [
                'name' => 'Coolify',
                'key' => 'ssh-ed25519 AAAA test@example.com',
            ],
        ],
    ]);

    expect($virtualMachine['id'])->toBe(17923);

    Http::assertSent(fn ($request) => $request->url() === 'https://developers.hostinger.com/api/vps/v1/virtual-machines'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request['item_id'] === 'hostingercom-vps-kvm2-usd-1m'
        && $request['setup']['data_center_id'] === 19
        && $request['setup']['template_id'] === 1130
        && $request['setup']['enable_backups'] === true
        && $request['setup']['public_key']['key'] === 'ssh-ed25519 AAAA test@example.com');
});

it('extracts a Hostinger public IPv4 address before falling back to IPv6', function () {
    $virtualMachine = [
        'ipv4' => [['address' => '203.0.113.10']],
        'ipv6' => [['address' => '2001:db8::10']],
    ];

    $service = new HostingerService('test-token');

    expect($service->getPublicIpAddress($virtualMachine))->toBe('203.0.113.10')
        ->and($service->getPublicIpAddress(['ipv4' => [], 'ipv6' => $virtualMachine['ipv6']]))->toBe('2001:db8::10');
});

it('waits for Hostinger to assign a public IP', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/17923' => Http::response([
            'id' => 17923,
            'state' => 'running',
            'ipv4' => [['address' => '203.0.113.10']],
        ]),
    ]);

    $service = new HostingerService('test-token');
    $virtualMachine = $service->waitForPublicIp([
        'id' => 17923,
        'state' => 'creating',
        'ipv4' => [],
    ], sleepMilliseconds: 0);

    expect($service->getPublicIpAddress($virtualMachine))->toBe('203.0.113.10');
});

it('finds a Hostinger virtual machine by public IP', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            [
                'id' => 17923,
                'ipv4' => [['address' => '203.0.113.10']],
                'ipv6' => [['address' => '2001:db8::10']],
            ],
        ]),
    ]);

    $service = new HostingerService('test-token');

    expect($service->findVirtualMachineByIp('203.0.113.10')['id'])->toBe(17923)
        ->and($service->findVirtualMachineByIp('2001:db8::10')['id'])->toBe(17923)
        ->and($service->findVirtualMachineByIp('198.51.100.1'))->toBeNull();
});

it('starts a Hostinger virtual machine', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/17923/start' => Http::response([
            'id' => 456,
            'name' => 'start',
            'state' => 'running',
        ]),
    ]);

    $action = (new HostingerService('test-token'))->startVirtualMachine(17923);

    expect($action['state'])->toBe('running');
});

it('raises the shared rate limit exception for Hostinger throttling', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/data-centers' => Http::response([
            'message' => 'Too many requests.',
        ], 429, ['Retry-After' => '30']),
    ]);

    expect(fn () => (new HostingerService('test-token'))->getDataCenters())
        ->toThrow(RateLimitException::class, 'Rate limit exceeded. Please try again later.');
});
