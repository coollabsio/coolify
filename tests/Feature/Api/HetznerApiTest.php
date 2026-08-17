<?php

use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('app.maintenance.driver', 'file');
    config()->set('app.maintenance.store', 'array');

    InstanceSettings::query()->whereKey(0)->delete();
    $settings = new InstanceSettings([
        'is_api_enabled' => true,
        'is_registration_enabled' => true,
    ]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Create an API token for the user
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    // Create a Hetzner cloud provider token
    $this->hetznerToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'name' => 'Test Hetzner Token',
        'token' => 'test-hetzner-api-token',
    ]);

    // Create a private key
    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Key',
        'description' => 'Test SSH key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
    ]);
});

describe('GET /api/v1/hetzner/locations', function () {
    test('gets Hetzner locations', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations*' => Http::response([
                'locations' => [
                    ['id' => 1, 'name' => 'nbg1', 'description' => 'Nuremberg 1 DC Park 1', 'country' => 'DE', 'city' => 'Nuremberg'],
                    ['id' => 2, 'name' => 'hel1', 'description' => 'Helsinki 1 DC Park 8', 'country' => 'FI', 'city' => 'Helsinki'],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/locations?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'nbg1']);
    });

    test('requires cloud_provider_token_id parameter', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/locations');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cloud_provider_token_id']);
    });

    test('returns 404 for non-existent token', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/locations?cloud_provider_token_id=non-existent-uuid');

        $response->assertStatus(404);
    });

    test('member read token cannot use a stored cloud provider token', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        session(['currentTeam' => $this->team]);
        $memberToken = $member->createToken('member-read', ['read'])->plainTextToken;

        Http::fake([
            'https://api.hetzner.cloud/v1/locations*' => Http::response([
                'locations' => [['id' => 1, 'name' => 'nbg1']],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$memberToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/locations?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertForbidden();
        Http::assertNothingSent();
    });
});

describe('GET /api/v1/hetzner/server-types', function () {
    test('gets Hetzner server types', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/server_types*' => Http::response([
                'server_types' => [
                    ['id' => 1, 'name' => 'cx11', 'description' => 'CX11', 'cores' => 1, 'memory' => 2.0, 'disk' => 20],
                    ['id' => 2, 'name' => 'cx21', 'description' => 'CX21', 'cores' => 2, 'memory' => 4.0, 'disk' => 40],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/server-types?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'cx11']);
    });

    test('filters out deprecated server types', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/server_types*' => Http::response([
                'server_types' => [
                    ['id' => 1, 'name' => 'cx11', 'deprecated' => false],
                    ['id' => 2, 'name' => 'cx21', 'deprecated' => true],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/server-types?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'cx11']);
        $response->assertJsonMissing(['name' => 'cx21']);
    });
});

describe('GET /api/v1/hetzner/images', function () {
    test('gets Hetzner images', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/images*' => Http::response([
                'images' => [
                    ['id' => 1, 'name' => 'ubuntu-20.04', 'type' => 'system', 'deprecated' => false],
                    ['id' => 2, 'name' => 'ubuntu-22.04', 'type' => 'system', 'deprecated' => false],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/images?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'ubuntu-20.04']);
    });

    test('filters out deprecated images', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/images*' => Http::response([
                'images' => [
                    ['id' => 1, 'name' => 'ubuntu-20.04', 'type' => 'system', 'deprecated' => false],
                    ['id' => 2, 'name' => 'ubuntu-16.04', 'type' => 'system', 'deprecated' => true],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/images?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'ubuntu-20.04']);
        $response->assertJsonMissing(['name' => 'ubuntu-16.04']);
    });

    test('filters out non-system images', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/images*' => Http::response([
                'images' => [
                    ['id' => 1, 'name' => 'ubuntu-20.04', 'type' => 'system', 'deprecated' => false],
                    ['id' => 2, 'name' => 'my-snapshot', 'type' => 'snapshot', 'deprecated' => false],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/images?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'ubuntu-20.04']);
        $response->assertJsonMissing(['name' => 'my-snapshot']);
    });
});

describe('GET /api/v1/hetzner/ssh-keys', function () {
    test('gets Hetzner SSH keys', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [
                    ['id' => 1, 'name' => 'my-key', 'fingerprint' => 'aa:bb:cc:dd'],
                    ['id' => 2, 'name' => 'another-key', 'fingerprint' => 'ee:ff:11:22'],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/ssh-keys?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'my-key']);
    });
});

describe('GET /api/v1/hetzner/firewalls', function () {
    test('gets Hetzner firewalls', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/firewalls*' => Http::response([
                'firewalls' => [
                    ['id' => 38, 'name' => 'web-firewall', 'rules' => []],
                    ['id' => 39, 'name' => 'ssh-firewall', 'rules' => []],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/firewalls?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertSuccessful();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'web-firewall']);
    });

    test('member read token cannot use a stored cloud provider token', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        session(['currentTeam' => $this->team]);
        $memberToken = $member->createToken('member-read', ['read'])->plainTextToken;

        Http::fake([
            'https://api.hetzner.cloud/v1/firewalls*' => Http::response([
                'firewalls' => [['id' => 38, 'name' => 'web-firewall']],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$memberToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/firewalls?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertForbidden();
        Http::assertNothingSent();
    });
});

describe('GET /api/v1/hetzner/networks', function () {
    test('gets Hetzner networks', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/networks*' => Http::response([
                'networks' => [
                    ['id' => 456, 'name' => 'private-eu', 'ip_range' => '10.0.0.0/16', 'subnets' => []],
                    ['id' => 457, 'name' => 'private-us', 'ip_range' => '10.1.0.0/16', 'subnets' => []],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/networks?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertSuccessful();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'private-eu']);
    });

    test('member read token cannot use a stored cloud provider token', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        session(['currentTeam' => $this->team]);
        $memberToken = $member->createToken('member-read', ['read'])->plainTextToken;

        Http::fake([
            'https://api.hetzner.cloud/v1/networks*' => Http::response([
                'networks' => [['id' => 456, 'name' => 'private-eu']],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$memberToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/networks?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertForbidden();
        Http::assertNothingSent();
    });
});

describe('POST /api/v1/servers/hetzner', function () {
    test('creates a Hetzner server', function () {
        // Mock Hetzner API calls
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123, 'fingerprint' => 'aa:bb:cc:dd'],
            ], 201),
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/servers' => Http::response([
                'server' => [
                    'id' => 456,
                    'name' => 'test-server',
                    'public_net' => [
                        'ipv4' => ['ip' => '1.2.3.4'],
                        'ipv6' => ['ip' => '2001:db8::1'],
                    ],
                ],
            ], 201),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid', 'hetzner_server_id', 'ip']);
        $response->assertJsonFragment(['hetzner_server_id' => 456, 'ip' => '1.2.3.4']);

        // Verify server was created in database
        $this->assertDatabaseHas('servers', [
            'name' => 'test-server',
            'ip' => '1.2.3.4',
            'team_id' => $this->team->id,
            'hetzner_server_id' => 456,
        ]);
    });

    test('enables backups after creating a Hetzner server when requested', function () {
        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.hetzner.cloud/v1/ssh_keys')) {
                return Http::response([
                    'ssh_keys' => [],
                    'meta' => ['pagination' => ['next_page' => null]],
                ], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/ssh_keys') {
                return Http::response([
                    'ssh_key' => ['id' => 123, 'fingerprint' => 'aa:bb:cc:dd'],
                ], 201);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/servers') {
                return Http::response([
                    'server' => [
                        'id' => 456,
                        'name' => 'test-server',
                        'public_net' => [
                            'ipv4' => ['ip' => '1.2.3.4'],
                            'ipv6' => ['ip' => '2001:db8::1'],
                        ],
                    ],
                ], 201);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/servers/456/actions/enable_backup') {
                return Http::response([
                    'action' => ['id' => 789, 'command' => 'enable_backup', 'status' => 'running'],
                ], 201);
            }

            return Http::response([], 404);
        });

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv4' => true,
            'enable_ipv6' => true,
            'enable_backups' => true,
        ]);

        $response->assertStatus(201);

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.hetzner.cloud/v1/servers/456/actions/enable_backup');
    });

    test('registers server when backup enablement fails after Hetzner creation', function () {
        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.hetzner.cloud/v1/ssh_keys')) {
                return Http::response([
                    'ssh_keys' => [],
                    'meta' => ['pagination' => ['next_page' => null]],
                ], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/ssh_keys') {
                return Http::response([
                    'ssh_key' => ['id' => 123, 'fingerprint' => 'aa:bb:cc:dd'],
                ], 201);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/servers') {
                return Http::response([
                    'server' => [
                        'id' => 456,
                        'name' => 'test-server',
                        'public_net' => [
                            'ipv4' => ['ip' => '1.2.3.4'],
                            'ipv6' => ['ip' => '2001:db8::1'],
                        ],
                    ],
                ], 201);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/servers/456/actions/enable_backup') {
                return Http::response([
                    'error' => ['message' => 'backup unavailable'],
                ], 500);
            }

            return Http::response([], 404);
        });

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv4' => true,
            'enable_ipv6' => true,
            'enable_backups' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['hetzner_server_id' => 456, 'ip' => '1.2.3.4']);
        $this->assertDatabaseHas('servers', [
            'name' => 'test-server',
            'team_id' => $this->team->id,
            'hetzner_server_id' => 456,
        ]);
    });

    test('generates server name if not provided', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123, 'fingerprint' => 'aa:bb:cc:dd'],
            ], 201),
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/servers' => Http::response([
                'server' => [
                    'id' => 456,
                    'public_net' => [
                        'ipv4' => ['ip' => '1.2.3.4'],
                    ],
                ],
            ], 201),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertStatus(201);

        // Verify a server was created with a generated name
        $this->assertDatabaseCount('servers', 1);
    });

    test('validates required fields', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', []);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Invalid request.',
            'error' => 'Invalid JSON.',
        ]);
    });

    test('validates cloud_provider_token_id exists', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => 'non-existent-uuid',
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Hetzner cloud provider token not found.']);
    });

    test('validates private_key_uuid exists', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'private_key_uuid' => 'non-existent-uuid',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Private key not found.']);
    });

    test('prefers IPv4 when both IPv4 and IPv6 are enabled', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123],
            ], 201),
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/servers' => Http::response([
                'server' => [
                    'id' => 456,
                    'public_net' => [
                        'ipv4' => ['ip' => '1.2.3.4'],
                        'ipv6' => ['ip' => '2001:db8::1'],
                    ],
                ],
            ], 201),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['ip' => '1.2.3.4']);
    });

    test('uses IPv6 when only IPv6 is enabled', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123],
            ], 201),
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/servers' => Http::response([
                'server' => [
                    'id' => 456,
                    'public_net' => [
                        'ipv4' => ['ip' => null],
                        'ipv6' => ['ip' => '2001:db8::1'],
                    ],
                ],
            ], 201),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv4' => false,
            'enable_ipv6' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['ip' => '2001:db8::1']);
    });

    test('rejects server creation when both public IP protocols are disabled', function () {
        Http::fake();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv4' => false,
            'enable_ipv6' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['enable_ipv4', 'enable_ipv6']);
        Http::assertNothingSent();
    });

    test('passes selected firewalls and networks to Hetzner server creation', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123],
            ], 201),
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/servers' => Http::response([
                'server' => [
                    'id' => 456,
                    'public_net' => [
                        'ipv4' => ['ip' => '1.2.3.4'],
                        'ipv6' => ['ip' => '2001:db8::1'],
                    ],
                ],
            ], 201),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'hetzner_firewall_ids' => [38, 39],
            'hetzner_network_ids' => [456, 457, 456],
        ]);

        $response->assertCreated();

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.hetzner.cloud/v1/servers'
                && $request['networks'] === [456, 457]
                && $request['firewalls'] === [
                    ['firewall' => 38],
                    ['firewall' => 39],
                ];
        });
    });

    test('rejects extra fields not in allowed list', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'private_key_uuid' => $this->privateKey->uuid,
            'invalid_field' => 'invalid_value',
        ]);

        $response->assertStatus(422);
    });

    test('rejects request without authentication', function () {
        $response = $this->postJson('/api/v1/servers/hetzner', [
            'cloud_provider_token_id' => $this->hetznerToken->uuid,
            'location' => 'nbg1',
            'server_type' => 'cx11',
            'image' => 15512617,
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertStatus(401);
    });
});

describe('error responses do not leak exception details', function () {
    test('locations endpoint returns generic 500 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc /var/secret/path'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/locations?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(500);
        $response->assertExactJson(['message' => 'Failed to fetch Hetzner locations.']);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
        expect($response->getContent())->not->toContain('/var/secret/path');
    });

    test('server-types endpoint returns generic 500 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/server_types*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/server-types?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(500);
        $response->assertExactJson(['message' => 'Failed to fetch Hetzner server types.']);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });

    test('images endpoint returns generic 500 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/images*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/images?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(500);
        $response->assertExactJson(['message' => 'Failed to fetch Hetzner images.']);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });

    test('ssh-keys endpoint returns generic 500 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/ssh-keys?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(500);
        $response->assertExactJson(['message' => 'Failed to fetch Hetzner SSH keys.']);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });

    test('firewalls endpoint returns generic 500 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/firewalls*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/firewalls?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(500);
        $response->assertExactJson(['message' => 'Failed to fetch Hetzner firewalls.']);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });

    test('networks endpoint returns generic 500 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/networks*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/networks?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(500);
        $response->assertExactJson(['message' => 'Failed to fetch Hetzner networks.']);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });
});
