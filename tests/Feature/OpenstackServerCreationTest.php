<?php

use App\Livewire\Server\New\ByOpenstack;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('osTestRsaKey')) {
    function osTestRsaKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $privateKey);

        return $privateKey;
    }
}

if (! function_exists('osTestCredentials')) {
    function osTestCredentials(): array
    {
        return [
            'auth_url' => 'https://identity.example/v3',
            'application_credential_id' => 'app-id',
            'application_credential_secret' => 'app-secret',
            'region' => 'RegionOne',
        ];
    }
}

if (! function_exists('osTestFakes')) {
    function osTestFakes(string $keyName): array
    {
        $catalog = [
            ['type' => 'compute', 'endpoints' => [['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://compute.example/v2.1']]],
            ['type' => 'image', 'endpoints' => [['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://image.example']]],
            ['type' => 'network', 'endpoints' => [['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://network.example']]],
        ];

        return [
            'identity.example/v3/auth/tokens' => Http::response(['token' => ['catalog' => $catalog]], 201, ['X-Subject-Token' => 'tok']),
            'compute.example/v2.1/flavors/detail' => Http::response(['flavors' => [
                ['id' => 'flavor-1', 'name' => 'm1.small', 'vcpus' => 1, 'ram' => 2048, 'disk' => 20],
                ['id' => 'flavor-diskless', 'name' => 'SCS-1V-4', 'vcpus' => 1, 'ram' => 4096, 'disk' => 0],
            ]], 200),
            'image.example/v2/images*' => Http::response(['images' => [
                ['id' => 'image-1', 'name' => 'Ubuntu 24.04'],
            ], 'next' => null], 200),
            'network.example/v2.0/networks' => Http::response(['networks' => [
                ['id' => 'net-private', 'name' => 'private', 'router:external' => false],
                ['id' => 'net-public', 'name' => 'public', 'router:external' => true],
            ]], 200),
            'compute.example/v2.1/os-availability-zone' => Http::response(['availabilityZoneInfo' => [
                ['zoneName' => 'nova', 'zoneState' => ['available' => true]],
            ]], 200),
            'network.example/v2.0/security-groups' => Http::response(['security_groups' => [[
                'id' => 'sg-1', 'name' => 'coolify', 'security_group_rules' => [
                    ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 22, 'port_range_max' => 22],
                    ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 80, 'port_range_max' => 80],
                    ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 443, 'port_range_max' => 443],
                    ['direction' => 'ingress', 'protocol' => 'icmp', 'port_range_min' => null, 'port_range_max' => null],
                ],
            ]]], 200),
            'compute.example/v2.1/os-keypairs' => Http::response(['keypairs' => [
                ['keypair' => ['name' => $keyName, 'fingerprint' => 'aa:bb']],
            ]], 200),
            'compute.example/v2.1/servers/*' => Http::response(['server' => [
                'id' => 'srv-1',
                'status' => 'ACTIVE',
                'addresses' => ['private' => [['addr' => '10.0.0.9', 'version' => 4, 'OS-EXT-IPS:type' => 'fixed']]],
            ]], 200),
            'compute.example/v2.1/servers' => Http::response(['server' => ['id' => 'srv-1']], 202),
            'network.example/v2.0/ports?device_id=*' => Http::response(['ports' => [['id' => 'port-1']]], 200),
            'network.example/v2.0/ports/*' => Http::response(['port' => ['id' => 'port-1', 'security_groups' => ['default-id']]], 200),
            'network.example/v2.0/floatingips' => Http::response(['floatingip' => [
                'id' => 'fip-1', 'floating_ip_address' => '203.0.113.5',
            ]], 201),
        ];
    }
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'name' => 'coolify-test-key',
        'private_key' => osTestRsaKey(),
        'team_id' => $this->team->id,
    ]);

    $this->osToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'openstack',
        'name' => 'Test OpenStack',
        'token' => json_encode(osTestCredentials()),
    ]);
});

it('creates a server with a floating IP through the wizard', function () {
    Http::fake(osTestFakes('coolify-test-key'));

    Livewire::test(ByOpenstack::class)
        ->set('selected_token_id', $this->osToken->id)
        ->call('nextStep')
        ->assertSet('current_step', 2)
        ->set('server_name', 'os-test-server')
        ->set('server_user', 'ubuntu')
        ->set('selected_flavor', 'flavor-1')
        ->set('selected_image', 'image-1')
        ->set('selected_network', 'net-private')
        ->set('assign_floating_ip', true)
        ->set('selected_external_network', 'net-public')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    $server = Server::where('openstack_server_id', 'srv-1')->first();

    expect($server)->not->toBeNull()
        ->and($server->ip)->toBe('203.0.113.5')
        ->and($server->user)->toBe('ubuntu')
        ->and($server->openstack_floating_ip_id)->toBe('fip-1')
        ->and($server->cloud_provider_token_id)->toBe($this->osToken->id);

    // The coolify group is attached to the port by ID (keeping default),
    // not passed at boot (which would drop default) or added by name (which
    // breaks when the project has duplicate security-group names).
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2.0/ports/port-1')
        && $request->method() === 'PUT'
        && in_array('sg-1', $request->data()['port']['security_groups'] ?? [], true)
        && in_array('default-id', $request->data()['port']['security_groups'] ?? [], true));
    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/servers') || $request->method() !== 'POST') {
            return true;
        }

        return ! isset($request->data()['server']['security_groups']);
    });
});

it('creates a server using the fixed IP when floating IP is disabled', function () {
    Http::fake(osTestFakes('coolify-test-key'));

    Livewire::test(ByOpenstack::class)
        ->set('selected_token_id', $this->osToken->id)
        ->call('nextStep')
        ->set('server_name', 'os-fixed-server')
        ->set('selected_flavor', 'flavor-1')
        ->set('selected_image', 'image-1')
        ->set('selected_network', 'net-private')
        ->set('assign_floating_ip', false)
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    $server = Server::where('openstack_server_id', 'srv-1')->first();

    expect($server)->not->toBeNull()
        ->and($server->ip)->toBe('10.0.0.9')
        ->and($server->openstack_floating_ip_id)->toBeNull();
});

it('requires a root volume size for a diskless flavor', function () {
    Http::fake(osTestFakes('coolify-test-key'));

    Livewire::test(ByOpenstack::class)
        ->set('selected_token_id', $this->osToken->id)
        ->call('nextStep')
        ->set('server_name', 'os-diskless')
        ->set('selected_flavor', 'flavor-diskless')
        ->set('selected_image', 'image-1')
        ->set('selected_network', 'net-private')
        ->set('assign_floating_ip', true)
        ->set('selected_external_network', 'net-public')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasErrors('volume_size');

    expect(Server::where('openstack_server_id', 'srv-1')->exists())->toBeFalse();
});

it('boots a diskless flavor from a volume when a size is given', function () {
    Http::fake(osTestFakes('coolify-test-key'));

    Livewire::test(ByOpenstack::class)
        ->set('selected_token_id', $this->osToken->id)
        ->call('nextStep')
        ->set('server_name', 'os-diskless')
        ->set('selected_flavor', 'flavor-diskless')
        ->set('volume_size', 20)
        ->set('selected_image', 'image-1')
        ->set('selected_network', 'net-private')
        ->set('assign_floating_ip', true)
        ->set('selected_external_network', 'net-public')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Server::where('openstack_server_id', 'srv-1')->exists())->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/servers') || $request->method() !== 'POST') {
            return true;
        }

        return ($request->data()['server']['block_device_mapping_v2'][0]['volume_size'] ?? null) === 20;
    });
});

it('requires an external network when floating IP is enabled', function () {
    Http::fake(osTestFakes('coolify-test-key'));

    Livewire::test(ByOpenstack::class)
        ->set('selected_token_id', $this->osToken->id)
        ->call('nextStep')
        ->set('server_name', 'os-test-server')
        ->set('selected_flavor', 'flavor-1')
        ->set('selected_image', 'image-1')
        ->set('selected_network', 'net-private')
        ->set('assign_floating_ip', true)
        ->set('selected_external_network', '')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasErrors('selected_external_network');

    expect(Server::where('openstack_server_id', 'srv-1')->exists())->toBeFalse();
});
