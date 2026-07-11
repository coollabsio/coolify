<?php

use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->whereKey(0)->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->digitalOceanToken = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'digitalocean',
        'token' => 'test-digitalocean-api-token',
    ]);

    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

describe('GET /api/v1/digitalocean/regions', function () {
    test('gets DigitalOcean regions', function () {
        Http::fake([
            'https://api.digitalocean.com/v2/regions*' => Http::response([
                'regions' => [
                    ['slug' => 'nyc1', 'name' => 'New York 1', 'available' => true],
                    ['slug' => 'ams3', 'name' => 'Amsterdam 3', 'available' => true],
                ],
                'links' => ['pages' => []],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/digitalocean/regions?cloud_provider_token_id='.$this->digitalOceanToken->uuid);

        $response->assertSuccessful();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['slug' => 'nyc1']);
    });

    test('requires cloud_provider_token_id parameter', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/digitalocean/regions');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['cloud_provider_token_id']);
    });
});

describe('POST /api/v1/servers/digitalocean', function () {
    test('creates a DigitalOcean droplet server', function () {
        Http::fake([
            'https://api.digitalocean.com/v2/account/keys' => Http::response([
                'ssh_key' => ['id' => 123, 'fingerprint' => 'aa:bb:cc:dd'],
            ], 201),
            'https://api.digitalocean.com/v2/account/keys*' => Http::response([
                'ssh_keys' => [],
                'links' => ['pages' => []],
            ], 200),
            'https://api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => [
                    'id' => 987,
                    'name' => 'test-server',
                    'status' => 'new',
                    'networks' => [
                        'v4' => [
                            ['ip_address' => '203.0.113.10', 'type' => 'public'],
                        ],
                        'v6' => [
                            ['ip_address' => '2001:db8::10', 'type' => 'public'],
                        ],
                    ],
                ],
            ], 202),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/digitalocean', [
            'cloud_provider_token_id' => $this->digitalOceanToken->uuid,
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'name' => 'test-server',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_ipv6' => true,
            'monitoring' => true,
            'cloud_init_script' => '#cloud-config',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid', 'digitalocean_droplet_id', 'ip']);
        $response->assertJsonFragment(['digitalocean_droplet_id' => 987, 'ip' => '203.0.113.10']);

        $this->assertDatabaseHas('servers', [
            'name' => 'test-server',
            'ip' => '203.0.113.10',
            'team_id' => $this->team->id,
            'digitalocean_droplet_id' => 987,
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.digitalocean.com/v2/droplets'
            && $request['region'] === 'nyc1'
            && $request['size'] === 's-1vcpu-1gb'
            && $request['image'] === 'ubuntu-24-04-x64'
            && $request['ssh_keys'] === [123]
            && $request['ipv6'] === true
            && $request['monitoring'] === true
            && $request['user_data'] === '#cloud-config');
    });

    test('validates required fields', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/digitalocean', ['name' => 'missing-required-fields']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'cloud_provider_token_id',
            'region',
            'size',
            'image',
            'private_key_uuid',
        ]);
    });

    test('returns 404 for a non-DigitalOcean token', function () {
        $hetznerToken = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
            'token' => 'test-hetzner-api-token',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers/digitalocean', [
            'cloud_provider_token_id' => $hetznerToken->uuid,
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertNotFound();
        $response->assertJson(['message' => 'DigitalOcean cloud provider token not found.']);
    });
});
