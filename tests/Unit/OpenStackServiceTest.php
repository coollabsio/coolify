<?php

use App\Services\OpenStackService;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function osCreds(): array
{
    return [
        'auth_url' => 'https://identity.example/v3',
        'application_credential_id' => 'app-cred-id',
        'application_credential_secret' => 'app-cred-secret',
        'region' => 'RegionOne',
    ];
}

function osAuthFake(): array
{
    $catalog = [
        ['type' => 'compute', 'endpoints' => [
            ['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://compute.example/v2.1'],
        ]],
        ['type' => 'image', 'endpoints' => [
            ['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://image.example'],
        ]],
        ['type' => 'network', 'endpoints' => [
            ['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://network.example'],
        ]],
    ];

    return [
        'identity.example/v3/auth/tokens' => Http::response(
            ['token' => ['catalog' => $catalog]],
            201,
            ['X-Subject-Token' => 'issued-token']
        ),
    ];
}

it('authenticates and returns the issued token', function () {
    Http::fake(osAuthFake());

    $service = new OpenStackService(osCreds());

    expect($service->authenticate())->toBe('issued-token');
});

it('throws when authentication fails', function () {
    Http::fake([
        'identity.example/v3/auth/tokens' => Http::response(['error' => ['message' => 'bad creds']], 401),
    ]);

    $service = new OpenStackService(osCreds());

    expect(fn () => $service->authenticate())->toThrow(Exception::class);
});

it('resolves endpoints from the catalog preferring the configured region', function () {
    Http::fake(osAuthFake());

    $service = new OpenStackService(osCreds());

    expect($service->endpointFor('compute'))->toBe('https://compute.example/v2.1')
        ->and($service->endpointFor('network'))->toBe('https://network.example');
});

it('lists flavors', function () {
    Http::fake(array_merge(osAuthFake(), [
        'compute.example/v2.1/flavors/detail' => Http::response([
            'flavors' => [
                ['id' => 'f1', 'name' => 'm1.small', 'vcpus' => 1, 'ram' => 2048, 'disk' => 20],
                ['id' => 'f2', 'name' => 'm1.large', 'vcpus' => 4, 'ram' => 8192, 'disk' => 80],
            ],
        ], 200),
    ]));

    $flavors = (new OpenStackService(osCreds()))->getFlavors();

    expect($flavors)->toHaveCount(2)
        ->and($flavors[0]['name'])->toBe('m1.small');
});

it('lists images', function () {
    Http::fake(array_merge(osAuthFake(), [
        'image.example/v2/images*' => Http::response([
            'images' => [
                ['id' => 'i1', 'name' => 'Ubuntu 24.04'],
                ['id' => 'i2', 'name' => 'Debian 12'],
            ],
            'next' => null,
        ], 200),
    ]));

    $images = (new OpenStackService(osCreds()))->getImages();

    expect($images)->toHaveCount(2)
        ->and($images[1]['name'])->toBe('Debian 12');
});

it('separates external networks from private networks', function () {
    Http::fake(array_merge(osAuthFake(), [
        'network.example/v2.0/networks' => Http::response([
            'networks' => [
                ['id' => 'n1', 'name' => 'private', 'router:external' => false],
                ['id' => 'n2', 'name' => 'public', 'router:external' => true],
            ],
        ], 200),
    ]));

    $service = new OpenStackService(osCreds());

    expect($service->getExternalNetworks())->toHaveCount(1)
        ->and($service->getExternalNetworks()[0]['id'])->toBe('n2');
});

it('finds a keypair by name', function () {
    Http::fake(array_merge(osAuthFake(), [
        'compute.example/v2.1/os-keypairs' => Http::response([
            'keypairs' => [
                ['keypair' => ['name' => 'coolify-key', 'fingerprint' => 'aa:bb']],
            ],
        ], 200),
    ]));

    $service = new OpenStackService(osCreds());

    expect($service->findKeypairByName('coolify-key'))->not->toBeNull()
        ->and($service->findKeypairByName('missing'))->toBeNull();
});

it('creates a server with base64-encoded user data', function () {
    Http::fake(array_merge(osAuthFake(), [
        'compute.example/v2.1/servers' => Http::response([
            'server' => ['id' => 'srv-123'],
        ], 202),
    ]));

    $service = new OpenStackService(osCreds());

    $server = $service->createServer([
        'name' => 'test',
        'imageRef' => 'i1',
        'flavorRef' => 'f1',
        'networkId' => 'n1',
        'key_name' => 'coolify-key',
        'userData' => "#cloud-config\nruncmd: [echo hi]",
    ]);

    expect($server['id'])->toBe('srv-123');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/servers') || $request->method() !== 'POST') {
            return true;
        }
        $body = $request->data();

        return isset($body['server']['user_data'])
            && base64_decode($body['server']['user_data']) === "#cloud-config\nruncmd: [echo hi]";
    });
});

it('boots from a volume when a volume size is provided', function () {
    Http::fake(array_merge(osAuthFake(), [
        'compute.example/v2.1/servers' => Http::response(['server' => ['id' => 'srv-vol']], 202),
    ]));

    $service = new OpenStackService(osCreds());

    $service->createServer([
        'name' => 'test',
        'imageRef' => 'image-1',
        'flavorRef' => 'diskless-flavor',
        'networkId' => 'net-1',
        'key_name' => 'k',
        'volumeSize' => 20,
    ]);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/servers') || $request->method() !== 'POST') {
            return true;
        }
        $server = $request->data()['server'];

        return ! isset($server['imageRef'])
            && $server['block_device_mapping_v2'][0]['source_type'] === 'image'
            && $server['block_device_mapping_v2'][0]['destination_type'] === 'volume'
            && $server['block_device_mapping_v2'][0]['volume_size'] === 20
            && $server['block_device_mapping_v2'][0]['delete_on_termination'] === true;
    });
});

it('creates the coolify security group and adds the ingress rules when missing', function () {
    Http::fake(function ($request) {
        return match (true) {
            str_contains($request->url(), '/auth/tokens') => Http::response(['token' => ['catalog' => [
                ['type' => 'network', 'endpoints' => [['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://network.example']]],
            ]]], 201, ['X-Subject-Token' => 'tok']),
            str_ends_with($request->url(), '/v2.0/security-groups') && $request->method() === 'GET' => Http::response(['security_groups' => []], 200),
            str_ends_with($request->url(), '/v2.0/security-groups') && $request->method() === 'POST' => Http::response(['security_group' => ['id' => 'sg-new', 'name' => 'coolify', 'security_group_rules' => []]], 201),
            str_contains($request->url(), '/v2.0/security-group-rules') => Http::response(['security_group_rule' => ['id' => 'r']], 201),
            default => Http::response([], 200),
        };
    });

    $id = (new OpenStackService(osCreds()))->ensureCoolifySecurityGroup();

    expect($id)->toBe('sg-new');

    // The group is created and a rule is added for each of 22/80/443 and ICMP.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2.0/security-groups') && $request->method() === 'POST');
    $rulePosts = 0;
    Http::assertSent(function ($request) use (&$rulePosts) {
        if (str_contains($request->url(), '/v2.0/security-group-rules') && $request->method() === 'POST') {
            $rulePosts++;
        }

        return true;
    });
    expect($rulePosts)->toBe(4);
});

it('does not duplicate rules when the coolify group already has them', function () {
    Http::fake(array_merge(osAuthFake(), [
        'network.example/v2.0/security-groups' => Http::response(['security_groups' => [[
            'id' => 'sg-1', 'name' => 'coolify', 'security_group_rules' => [
                ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 22, 'port_range_max' => 22],
                ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 80, 'port_range_max' => 80],
                ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 443, 'port_range_max' => 443],
                ['direction' => 'ingress', 'protocol' => 'icmp', 'port_range_min' => null, 'port_range_max' => null],
            ],
        ]]], 200),
    ]));

    (new OpenStackService(osCreds()))->ensureCoolifySecurityGroup();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2.0/security-group-rules'));
});

it('attaches a security group to a port by id, keeping existing groups', function () {
    Http::fake(array_merge(osAuthFake(), [
        'network.example/v2.0/ports/port-1' => Http::response(['port' => ['id' => 'port-1', 'security_groups' => ['default-id']]], 200),
    ]));

    (new OpenStackService(osCreds()))->attachSecurityGroupToPort('port-1', 'coolify-id');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v2.0/ports/port-1') || $request->method() !== 'PUT') {
            return true;
        }
        $groups = $request->data()['port']['security_groups'];

        return in_array('default-id', $groups, true) && in_array('coolify-id', $groups, true);
    });
});

it('does not re-add a security group already on the port', function () {
    Http::fake(array_merge(osAuthFake(), [
        'network.example/v2.0/ports/port-1' => Http::response(['port' => ['id' => 'port-1', 'security_groups' => ['default-id', 'coolify-id']]], 200),
    ]));

    (new OpenStackService(osCreds()))->attachSecurityGroupToPort('port-1', 'coolify-id');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2.0/ports/port-1') && $request->method() === 'PUT');
});

it('finds the server port id', function () {
    Http::fake(array_merge(osAuthFake(), [
        'network.example/v2.0/ports*' => Http::response([
            'ports' => [['id' => 'port-1']],
        ], 200),
    ]));

    expect((new OpenStackService(osCreds()))->getServerPortId('srv-123'))->toBe('port-1');
});

it('allocates a floating ip bound to a port', function () {
    Http::fake(array_merge(osAuthFake(), [
        'network.example/v2.0/floatingips' => Http::response([
            'floatingip' => ['id' => 'fip-1', 'floating_ip_address' => '203.0.113.10'],
        ], 201),
    ]));

    $fip = (new OpenStackService(osCreds()))->allocateFloatingIp('n2', 'port-1');

    expect($fip['id'])->toBe('fip-1')
        ->and($fip['floating_ip_address'])->toBe('203.0.113.10');
});

it('extracts the fixed ipv4 address from a server payload', function () {
    $service = new OpenStackService(osCreds());

    $ip = $service->getServerFixedIp([
        'addresses' => [
            'private' => [
                ['addr' => '10.0.0.5', 'version' => 4, 'OS-EXT-IPS:type' => 'fixed'],
            ],
        ],
    ]);

    expect($ip)->toBe('10.0.0.5');
});

it('releases a floating ip', function () {
    Http::fake(array_merge(osAuthFake(), [
        'network.example/v2.0/floatingips/fip-1' => Http::response(null, 204),
    ]));

    (new OpenStackService(osCreds()))->releaseFloatingIp('fip-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/floatingips/fip-1') && $request->method() === 'DELETE');
});
