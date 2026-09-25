<?php

use App\Exceptions\RateLimitException;
use App\Services\HostingerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

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

it('returns only KVM plans from the Hostinger VPS catalog', function () {
    Http::fake([
        'https://developers.hostinger.com/api/billing/v1/catalog?category=VPS' => Http::response([
            ['id' => 'hostingercom-vps-kvm1', 'name' => 'KVM 1', 'prices' => []],
            ['id' => 'hostingercom-vps-kvmminecraftalex', 'name' => 'Game Panel 1', 'prices' => []],
            ['id' => 'hostingercom-vps-kvm8', 'name' => 'KVM 8', 'prices' => []],
            ['id' => 'hostingercom-vps-kvmminecraftwolf', 'name' => 'Game Panel 8', 'prices' => []],
            ['name' => 'Missing id', 'prices' => []],
        ]),
    ]);

    expect(collect((new HostingerService('test-token'))->getCatalogItems())->pluck('id')->all())
        ->toBe(['hostingercom-vps-kvm1', 'hostingercom-vps-kvm8']);
});

it('fetches and attaches Hostinger account SSH keys', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/public-keys' => Http::response([
            'data' => [['id' => 42, 'name' => 'Operations', 'key' => 'ssh-ed25519 AAAA']],
        ]),
        'https://developers.hostinger.com/api/vps/v1/public-keys/attach/17923' => Http::response([
            'id' => 456,
            'state' => 'running',
        ]),
    ]);

    $service = new HostingerService('test-token');

    expect($service->getPublicKeys()[0]['id'])->toBe(42)
        ->and($service->attachPublicKeys(17923, ['42'])['id'])->toBe(456);

    Http::assertSent(fn ($request) => $request->url() === 'https://developers.hostinger.com/api/vps/v1/public-keys/attach/17923'
        && $request['ids'] === [42]);
});

it('fetches Hostinger post-install scripts', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/post-install-scripts' => Http::response([
            'data' => [['id' => 73, 'name' => 'Bootstrap Coolify']],
        ]),
    ]);

    expect((new HostingerService('test-token'))->getPostInstallScripts()[0]['id'])->toBe(73);
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

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://developers.hostinger.com/api/vps/v1/virtual-machines'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request['item_id'] === 'hostingercom-vps-kvm2-usd-1m'
        && $request['setup']['data_center_id'] === 19
        && $request['setup']['template_id'] === 1130
        && $request['setup']['enable_backups'] === true
        && $request['setup']['public_key']['key'] === 'ssh-ed25519 AAAA test@example.com');
});

it('only sends a fully qualified hostname to Hostinger', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'virtual_machine' => ['id' => 17923, 'state' => 'creating'],
        ]),
    ]);

    $service = new HostingerService('test-token');
    $service->purchaseVirtualMachine([
        'item_id' => 'hostingercom-vps-kvm1-usd-1m',
        'setup' => ['data_center_id' => 19, 'template_id' => 1130, 'hostname' => 'coolify-server'],
    ]);
    $service->purchaseVirtualMachine([
        'item_id' => 'hostingercom-vps-kvm1-usd-1m',
        'setup' => ['data_center_id' => 19, 'template_id' => 1130, 'hostname' => 'coolify.example.com'],
    ]);

    $sentHostnames = Http::recorded()->filter(fn ($record) => $record[0]->method() === 'POST')->values()->map(fn ($record) => $record[0]['setup']['hostname'] ?? null)->all();

    expect($sentHostnames)->toBe([null, 'coolify.example.com']);
});

it('does not retry a failed Hostinger purchase', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => fn ($request) => $request->method() === 'GET'
            ? Http::response([])
            : Http::response(['message' => 'Server error'], 500),
    ]);

    expect(fn () => (new HostingerService('test-token'))->purchaseVirtualMachine([
        'item_id' => 'hostingercom-vps-kvm2-usd-1m',
        'setup' => ['data_center_id' => 19, 'template_id' => 1130],
    ]))->toThrow(Exception::class, 'Hostinger API error: Server error');

    Http::assertSentCount(3);
    expect(Http::recorded()->filter(fn ($record) => $record[0]->method() === 'POST'))->toHaveCount(1);
});

it('sets up a charged Hostinger VPS when the purchase returns an error', function () {
    $listRequests = 0;
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/2008976/setup' => Http::response([
            'id' => 2008976,
            'state' => 'creating',
            'ipv4' => [['address' => '72.61.180.163']],
        ]),
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => function ($request) use (&$listRequests) {
            if ($request->method() === 'POST') {
                return Http::response(['message' => '[VPS:2004] Wrong hostname FQDN format'], 422);
            }

            return Http::response($listRequests++ === 0
                ? [['id' => 100, 'state' => 'running']]
                : [['id' => 100, 'state' => 'running'], ['id' => 2008976, 'state' => 'initial']]);
        },
    ]);

    $virtualMachine = (new HostingerService('test-token'))->purchaseVirtualMachine([
        'item_id' => 'hostingercom-vps-kvm1-usd-1m',
        'setup' => ['data_center_id' => 19, 'template_id' => 1077, 'enable_backups' => false],
    ]);

    expect($virtualMachine['id'])->toBe(2008976)
        ->and($virtualMachine['state'])->toBe('creating');

    Http::assertSent(fn ($request) => $request->url() === 'https://developers.hostinger.com/api/vps/v1/virtual-machines/2008976/setup'
        && $request['data_center_id'] === 19
        && $request['template_id'] === 1077
        && $request['enable_backups'] === false);
});

it('returns a charged Hostinger VPS that still needs setup when the setup retry fails', function () {
    $listRequests = 0;
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/2008976/setup' => Http::response(['message' => 'Setup failed'], 422),
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => function ($request) use (&$listRequests) {
            if ($request->method() === 'POST') {
                return Http::response(['message' => 'Setup failed'], 422);
            }

            return Http::response($listRequests++ === 0 ? [] : [['id' => 2008976, 'state' => 'initial']]);
        },
    ]);

    $virtualMachine = (new HostingerService('test-token'))->purchaseVirtualMachine([
        'item_id' => 'hostingercom-vps-kvm1-usd-1m',
        'setup' => ['data_center_id' => 19, 'template_id' => 1077],
    ]);

    expect($virtualMachine)->toBe(['id' => 2008976, 'state' => 'initial']);
});

it('reports a Hostinger purchase whose payment is still processing', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'id' => 2957086,
            'subscription_id' => 'Azz353Uhl1xC54pR0',
            'status' => 'payment_initiated',
            'message' => 'Payment is being processed.',
        ], 202),
    ]);
    Log::shouldReceive('warning')->once()->with('Hostinger VPS order did not return a virtual machine', [
        'id' => 2957086,
        'subscription_id' => 'Azz353Uhl1xC54pR0',
        'status' => 'payment_initiated',
        'message' => 'Payment is being processed.',
    ]);

    expect(fn () => (new HostingerService('test-token'))->purchaseVirtualMachine([
        'item_id' => 'hostingercom-vps-kvm2-usd-1m',
        'setup' => ['data_center_id' => 19, 'template_id' => 1130],
    ]))->toThrow(Exception::class, 'Hostinger order 2957086 is waiting for payment. Finish the VPS setup in hPanel.');
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

it('stops waiting for a Hostinger public IP after 10 attempts by default', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/17923' => Http::response([
            'id' => 17923,
            'state' => 'initial',
            'ipv4' => [],
        ]),
    ]);

    $service = new HostingerService('test-token');
    $virtualMachine = $service->waitForPublicIp([
        'id' => 17923,
        'state' => 'initial',
        'ipv4' => [],
    ], sleepMilliseconds: 0);

    expect($service->getPublicIpAddress($virtualMachine))->toBeNull();
    Http::assertSentCount(10);
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
