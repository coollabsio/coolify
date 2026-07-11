<?php

use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->vultrToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'vultr',
        'token' => 'test-vultr-api-token',
        'name' => 'Test Vultr Token',
    ]);

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Private Key',
        'description' => 'Test private key',
        'private_key' => testPrivateKey(),
    ]);
});

function testPrivateKey(): string
{
    return <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
}

function testPublicKey(): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68';
}

describe('GET /api/v1/vultr/regions', function () {
    test('gets Vultr regions', function () {
        Http::fake([
            'https://api.vultr.com/v2/regions*' => Http::response([
                'regions' => [
                    ['id' => 'ewr', 'city' => 'New Jersey', 'country' => 'US'],
                    ['id' => 'ams', 'city' => 'Amsterdam', 'country' => 'NL'],
                ],
                'meta' => ['links' => ['next' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/vultr/regions?cloud_provider_token_id='.$this->vultrToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['id' => 'ewr']);
    });

    test('requires cloud_provider_token_id parameter', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/vultr/regions');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cloud_provider_token_id']);
    });
});

describe('GET /api/v1/vultr/plans', function () {
    test('gets Vultr plans', function () {
        Http::fake([
            'https://api.vultr.com/v2/plans*' => Http::response([
                'plans' => [
                    ['id' => 'vc2-1c-1gb', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25],
                    ['id' => 'vc2-2c-2gb', 'vcpu_count' => 2, 'ram' => 2048, 'disk' => 55],
                ],
                'meta' => ['links' => ['next' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/vultr/plans?cloud_provider_token_uuid='.$this->vultrToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['id' => 'vc2-1c-1gb']);
    });
});

describe('GET /api/v1/vultr/os', function () {
    test('gets Vultr operating systems', function () {
        Http::fake([
            'https://api.vultr.com/v2/os*' => Http::response([
                'os' => [
                    ['id' => 2284, 'name' => 'Ubuntu 24.04 LTS x64'],
                    ['id' => 2136, 'name' => 'Debian 12 x64'],
                ],
                'meta' => ['links' => ['next' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/vultr/os?cloud_provider_token_id='.$this->vultrToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'Ubuntu 24.04 LTS x64']);
    });
});

describe('GET /api/v1/vultr/ssh-keys', function () {
    test('gets Vultr SSH keys', function () {
        Http::fake([
            'https://api.vultr.com/v2/ssh-keys*' => Http::response([
                'ssh_keys' => [
                    ['id' => 'key-1', 'name' => 'my-key', 'ssh_key' => testPublicKey()],
                    ['id' => 'key-2', 'name' => 'another-key', 'ssh_key' => 'ssh-ed25519 AAAAother'],
                ],
                'meta' => ['links' => ['next' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/vultr/ssh-keys?cloud_provider_token_id='.$this->vultrToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'my-key']);
    });
});

describe('POST /api/v1/servers/vultr', function () {
    test('creates a Vultr server', function () {
        Http::fake([
            'https://api.vultr.com/v2/ssh-keys' => Http::response([
                'ssh_key' => ['id' => 'key-1', 'ssh_key' => testPublicKey()],
            ], 201),
            'https://api.vultr.com/v2/ssh-keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['links' => ['next' => null]],
            ], 200),
            'https://api.vultr.com/v2/instances' => Http::response([
                'instance' => [
                    'id' => 'instance-1',
                    'label' => 'test-server',
                    'main_ip' => '1.2.3.4',
                    'v6_main_ip' => '2001:db8::1',
                    'status' => 'pending',
                ],
            ], 202),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/vultr', [
            'cloud_provider_token_id' => $this->vultrToken->uuid,
            'region' => 'ewr',
            'plan' => 'vc2-1c-1gb',
            'os_id' => 2284,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv6' => true,
            'cloud_init_script' => "#cloud-config\npackages:\n  - curl",
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid', 'vultr_instance_id', 'ip']);
        $response->assertJsonFragment(['vultr_instance_id' => 'instance-1', 'ip' => '1.2.3.4']);

        $this->assertDatabaseHas('servers', [
            'name' => 'test-server',
            'ip' => '1.2.3.4',
            'team_id' => $this->team->id,
            'vultr_instance_id' => 'instance-1',
            'vultr_instance_status' => 'pending',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.vultr.com/v2/instances'
                && $request['region'] === 'ewr'
                && $request['plan'] === 'vc2-1c-1gb'
                && $request['os_id'] === 2284
                && $request['sshkey_id'] === ['key-1']
                && $request['user_data'] === base64_encode("#cloud-config\npackages:\n  - curl");
        });
    });

    test('waits for Vultr to assign a real public IP when creation returns placeholder IP', function () {
        Http::fake([
            'https://api.vultr.com/v2/ssh-keys' => Http::response([
                'ssh_key' => ['id' => 'key-1', 'ssh_key' => testPublicKey()],
            ], 201),
            'https://api.vultr.com/v2/ssh-keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['links' => ['next' => null]],
            ], 200),
            'https://api.vultr.com/v2/instances' => Http::response([
                'instance' => [
                    'id' => 'instance-1',
                    'main_ip' => '0.0.0.0',
                    'status' => 'pending',
                ],
            ], 202),
            'https://api.vultr.com/v2/instances/instance-1' => Http::response([
                'instance' => [
                    'id' => 'instance-1',
                    'main_ip' => '1.2.3.4',
                    'status' => 'active',
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/vultr', [
            'cloud_provider_token_id' => $this->vultrToken->uuid,
            'region' => 'ewr',
            'plan' => 'vc2-1c-1gb',
            'os_id' => 2284,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['ip' => '1.2.3.4']);

        $this->assertDatabaseHas('servers', [
            'name' => 'test-server',
            'ip' => '1.2.3.4',
            'vultr_instance_status' => 'active',
        ]);
    });

    test('reuses existing matching Vultr SSH key', function () {
        Http::fake([
            'https://api.vultr.com/v2/ssh-keys*' => Http::response([
                'ssh_keys' => [
                    ['id' => 'existing-key', 'name' => 'Existing', 'ssh_key' => testPublicKey().' comment'],
                ],
                'meta' => ['links' => ['next' => null]],
            ], 200),
            'https://api.vultr.com/v2/instances' => Http::response([
                'instance' => [
                    'id' => 'instance-1',
                    'main_ip' => '1.2.3.4',
                    'status' => 'pending',
                ],
            ], 202),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/vultr', [
            'cloud_provider_token_id' => $this->vultrToken->uuid,
            'region' => 'ewr',
            'plan' => 'vc2-1c-1gb',
            'os_id' => 2284,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertStatus(201);

        Http::assertNotSent(function ($request) {
            return $request->url() === 'https://api.vultr.com/v2/ssh-keys'
                && $request->method() === 'POST';
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.vultr.com/v2/instances'
                && $request['sshkey_id'] === ['existing-key'];
        });
    });

    test('validates required fields', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/vultr', []);

        $response->assertStatus(400);
    });

    test('returns 404 for non-existent Vultr token', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/vultr', [
            'cloud_provider_token_id' => 'non-existent-uuid',
            'region' => 'ewr',
            'plan' => 'vc2-1c-1gb',
            'os_id' => 2284,
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Vultr cloud provider token not found.']);
    });

    test('uses IPv6 when public IPv4 is disabled', function () {
        Http::fake([
            'https://api.vultr.com/v2/ssh-keys' => Http::response([
                'ssh_key' => ['id' => 'key-1', 'ssh_key' => testPublicKey()],
            ], 201),
            'https://api.vultr.com/v2/ssh-keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['links' => ['next' => null]],
            ], 200),
            'https://api.vultr.com/v2/instances' => Http::response([
                'instance' => [
                    'id' => 'instance-1',
                    'main_ip' => '',
                    'v6_main_ip' => '2001:db8::1',
                    'status' => 'pending',
                ],
            ], 202),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/vultr', [
            'cloud_provider_token_id' => $this->vultrToken->uuid,
            'region' => 'ewr',
            'plan' => 'vc2-1c-1gb',
            'os_id' => 2284,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv6' => true,
            'disable_public_ipv4' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['ip' => '2001:db8::1']);
    });

    test('requires IPv6 when public IPv4 is disabled', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/vultr', [
            'cloud_provider_token_id' => $this->vultrToken->uuid,
            'region' => 'ewr',
            'plan' => 'vc2-1c-1gb',
            'os_id' => 2284,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv6' => false,
            'disable_public_ipv4' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['enable_ipv6']);
    });
});
