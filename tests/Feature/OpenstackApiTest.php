<?php

use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

if (! function_exists('osApiRsaKey')) {
    function osApiRsaKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);

        return $privateKey;
    }
}

if (! function_exists('osApiCredentials')) {
    function osApiCredentials(): array
    {
        return [
            'auth_url' => 'https://identity.example/v3',
            'application_credential_id' => 'app-id',
            'application_credential_secret' => 'app-secret',
            'region' => 'RegionOne',
        ];
    }
}

if (! function_exists('osApiFakes')) {
    function osApiFakes(string $keyName): array
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
            ]], 200),
            'compute.example/v2.1/os-keypairs' => Http::response(['keypairs' => [
                ['keypair' => ['name' => $keyName, 'fingerprint' => 'aa:bb']],
            ]], 200),
            'network.example/v2.0/security-groups' => Http::response(['security_groups' => [[
                'id' => 'sg-1', 'name' => 'coolify', 'security_group_rules' => [
                    ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 22, 'port_range_max' => 22],
                    ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 80, 'port_range_max' => 80],
                    ['direction' => 'ingress', 'protocol' => 'tcp', 'port_range_min' => 443, 'port_range_max' => 443],
                    ['direction' => 'ingress', 'protocol' => 'icmp', 'port_range_min' => null, 'port_range_max' => null],
                ],
            ]]], 200),
            'compute.example/v2.1/servers/*' => Http::response(['server' => ['id' => 'srv-1', 'status' => 'ACTIVE']], 200),
            'compute.example/v2.1/servers' => Http::response(['server' => ['id' => 'srv-1']], 202),
            'network.example/v2.0/ports?device_id=*' => Http::response(['ports' => [['id' => 'port-1']]], 200),
            'network.example/v2.0/ports/*' => Http::response(['port' => ['id' => 'port-1', 'security_groups' => ['default-id']]], 200),
            'network.example/v2.0/floatingips' => Http::response(['floatingip' => ['id' => 'fip-1', 'floating_ip_address' => '203.0.113.5']], 201),
        ];
    }
}

beforeEach(function () {
    if (! InstanceSettings::find(0)) {
        InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0, 'is_api_enabled' => true]));
    }

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;

    $this->osToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'openstack',
        'name' => 'Test OpenStack',
        'token' => json_encode(osApiCredentials()),
    ]);

    $this->privateKey = PrivateKey::create([
        'name' => 'coolify-api-key',
        'private_key' => osApiRsaKey(),
        'team_id' => $this->team->id,
    ]);
});

describe('GET /api/v1/openstack/flavors', function () {
    test('lists flavors for a valid token', function () {
        Http::fake(osApiFakes('coolify-api-key'));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->getJson('/api/v1/openstack/flavors?cloud_provider_token_uuid='.$this->osToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'm1.small']);
    });

    test('requires a token parameter', function () {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->getJson('/api/v1/openstack/flavors');

        $response->assertStatus(422);
    });

    test('returns 404 for a non-existent token', function () {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->getJson('/api/v1/openstack/flavors?cloud_provider_token_uuid=missing');

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/servers/openstack', function () {
    test('creates an OpenStack server with a floating IP', function () {
        Http::fake(osApiFakes('coolify-api-key'));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->postJson('/api/v1/servers/openstack', [
                'cloud_provider_token_uuid' => $this->osToken->uuid,
                'name' => 'api-openstack',
                'user' => 'ubuntu',
                'flavor' => 'flavor-1',
                'image' => 'image-1',
                'network' => 'net-private',
                'assign_floating_ip' => true,
                'external_network' => 'net-public',
                'private_key_uuid' => $this->privateKey->uuid,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['openstack_server_id' => 'srv-1', 'ip' => '203.0.113.5']);

        $this->assertDatabaseHas('servers', [
            'openstack_server_id' => 'srv-1',
            'openstack_floating_ip_id' => 'fip-1',
            'user' => 'ubuntu',
        ]);
    });

    test('rejects a floating IP request without an external network', function () {
        Http::fake(osApiFakes('coolify-api-key'));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->postJson('/api/v1/servers/openstack', [
                'cloud_provider_token_uuid' => $this->osToken->uuid,
                'flavor' => 'flavor-1',
                'image' => 'image-1',
                'network' => 'net-private',
                'assign_floating_ip' => true,
                'private_key_uuid' => $this->privateKey->uuid,
            ]);

        $response->assertStatus(422);
    });
});

describe('POST /api/v1/cloud-tokens (openstack)', function () {
    test('creates an OpenStack credential when authentication succeeds', function () {
        Http::fake([
            'identity.example/v3/auth/tokens' => Http::response(['token' => ['catalog' => []]], 201, ['X-Subject-Token' => 'tok']),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->postJson('/api/v1/cloud-tokens', [
                'provider' => 'openstack',
                'name' => 'My OpenStack',
                'auth_url' => 'https://identity.example/v3',
                'application_credential_id' => 'app-id',
                'application_credential_secret' => 'app-secret',
                'region' => 'RegionOne',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('cloud_provider_tokens', [
            'team_id' => $this->team->id,
            'provider' => 'openstack',
            'name' => 'My OpenStack',
        ]);
    });

    test('rejects OpenStack credentials that fail authentication', function () {
        Http::fake([
            'identity.example/v3/auth/tokens' => Http::response(['error' => ['message' => 'bad']], 401),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->postJson('/api/v1/cloud-tokens', [
                'provider' => 'openstack',
                'name' => 'My OpenStack',
                'auth_url' => 'https://identity.example/v3',
                'application_credential_id' => 'app-id',
                'application_credential_secret' => 'wrong',
            ]);

        $response->assertStatus(400);
    });

    test('validates required OpenStack fields', function () {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->postJson('/api/v1/cloud-tokens', [
                'provider' => 'openstack',
                'name' => 'My OpenStack',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['auth_url', 'application_credential_id', 'application_credential_secret']);
    });
});
