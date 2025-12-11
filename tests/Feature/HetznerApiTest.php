<?php

use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Create an API token for the user
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    // Create a Hetzner cloud provider token
    $this->hetznerToken = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-api-token',
    ]);

    // Create a private key
    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
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

describe('POST /api/v1/servers/hetzner', function () {
    test('creates a Hetzner server', function () {
        // Mock Hetzner API calls
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123, 'fingerprint' => 'aa:bb:cc:dd'],
            ], 201),
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

    test('generates server name if not provided', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123, 'fingerprint' => 'aa:bb:cc:dd'],
            ], 201),
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

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'cloud_provider_token_id',
            'location',
            'server_type',
            'image',
            'private_key_uuid',
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
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123],
            ], 201),
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
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 123],
            ], 201),
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
