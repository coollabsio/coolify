<?php

use App\Services\VultrService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

it('gets instances from Vultr API', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances*' => Http::response([
            'instances' => [
                [
                    'id' => 'instance-1',
                    'label' => 'test-server-1',
                    'status' => 'active',
                    'main_ip' => '123.45.67.89',
                    'v6_main_ip' => '2001:db8::1',
                ],
                [
                    'id' => 'instance-2',
                    'label' => 'test-server-2',
                    'status' => 'stopped',
                    'main_ip' => '98.76.54.32',
                    'v6_main_ip' => '2001:db8::2',
                ],
            ],
            'meta' => ['links' => ['next' => null]],
        ], 200),
    ]);

    $service = new VultrService('fake-token');
    $instances = $service->getInstances();

    expect($instances)->toBeArray()
        ->and($instances)->toHaveCount(2)
        ->and($instances[0]['id'])->toBe('instance-1')
        ->and($instances[1]['id'])->toBe('instance-2');
});

it('follows cursor pagination', function () {
    Http::fake([
        'https://api.vultr.com/v2/regions?per_page=100' => Http::response([
            'regions' => [
                ['id' => 'ewr', 'city' => 'New Jersey'],
            ],
            'meta' => [
                'links' => [
                    'next' => 'https://api.vultr.com/v2/regions?cursor=next-cursor',
                ],
            ],
        ], 200),
        'https://api.vultr.com/v2/regions?per_page=100&cursor=next-cursor' => Http::response([
            'regions' => [
                ['id' => 'ams', 'city' => 'Amsterdam'],
            ],
            'meta' => ['links' => ['next' => null]],
        ], 200),
    ]);

    $service = new VultrService('fake-token');
    $regions = $service->getRegions();

    expect($regions)->toHaveCount(2)
        ->and($regions[0]['id'])->toBe('ewr')
        ->and($regions[1]['id'])->toBe('ams');
});

it('base64 encodes user data when creating an instance', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'main_ip' => '123.45.67.89',
                'status' => 'pending',
            ],
        ], 202),
    ]);

    $service = new VultrService('fake-token');
    $service->createInstance([
        'region' => 'ewr',
        'plan' => 'vc2-1c-1gb',
        'os_id' => 2284,
        'user_data' => "#cloud-config\npackages:\n  - curl",
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.vultr.com/v2/instances'
            && $request['user_data'] === base64_encode("#cloud-config\npackages:\n  - curl");
    });
});

it('waits for Vultr to replace placeholder public IP', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'main_ip' => '123.45.67.89',
                'status' => 'active',
            ],
        ], 200),
    ]);

    $service = new VultrService('fake-token');
    $instance = $service->waitForPublicIp([
        'id' => 'instance-1',
        'main_ip' => '0.0.0.0',
        'status' => 'pending',
    ], sleepMilliseconds: 0);

    expect($service->getPublicIp($instance))->toBe('123.45.67.89');
});

it('finds an instance by IPv4 or IPv6', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances*' => Http::response([
            'instances' => [
                [
                    'id' => 'instance-1',
                    'main_ip' => '123.45.67.89',
                    'v6_main_ip' => '2001:db8::1',
                ],
            ],
            'meta' => ['links' => ['next' => null]],
        ], 200),
    ]);

    $service = new VultrService('fake-token');

    expect($service->findInstanceByIp('123.45.67.89')['id'])->toBe('instance-1')
        ->and($service->findInstanceByIp('2001:db8::1')['id'])->toBe('instance-1')
        ->and($service->findInstanceByIp('1.2.3.4'))->toBeNull();
});
