<?php

use App\Jobs\ValidateNewCloudServerJob;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ValidateNewCloudServerJob::class]);

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
                    ['id' => 1, 'name' => 'cx11', 'description' => 'CX11', 'cores' => 1, 'memory' => 2.0, 'disk' => 20, 'locations' => [['id' => 1, 'name' => 'nbg1', 'available' => true, 'deprecation' => null]]],
                    ['id' => 2, 'name' => 'cx21', 'description' => 'CX21', 'cores' => 2, 'memory' => 4.0, 'disk' => 40, 'locations' => [['id' => 1, 'name' => 'nbg1', 'available' => true, 'deprecation' => null]]],
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
                    ['id' => 1, 'name' => 'cx11', 'deprecated' => false, 'locations' => [['id' => 1, 'name' => 'nbg1', 'available' => true, 'deprecation' => null]]],
                    ['id' => 2, 'name' => 'cx21', 'deprecated' => true, 'locations' => [['id' => 1, 'name' => 'nbg1', 'available' => true, 'deprecation' => null]]],
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

    test('removes locations where a server type is out of stock or deprecated', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/server_types*' => Http::response([
                'server_types' => [
                    ['id' => 1, 'name' => 'cx22', 'locations' => [
                        ['id' => 1, 'name' => 'nbg1', 'available' => true, 'deprecation' => null],
                        ['id' => 2, 'name' => 'fsn1', 'available' => false, 'deprecation' => null],
                        ['id' => 3, 'name' => 'hel1', 'available' => true, 'deprecation' => ['announced' => '2025-09-24T00:00:00+00:00', 'unavailable_after' => '2025-12-31T00:00:00+00:00']],
                    ]],
                    ['id' => 2, 'name' => 'cpx11', 'locations' => [
                        ['id' => 2, 'name' => 'fsn1', 'available' => false, 'deprecation' => null],
                    ]],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/hetzner/server-types?cloud_provider_token_uuid='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        expect($response->json())->toHaveCount(1)
            ->and($response->json('0.name'))->toBe('cx22')
            ->and(collect($response->json('0.locations'))->pluck('name')->all())->toBe(['nbg1']);
    });

    test('returns only server types that can be ordered in the given location', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/server_types*' => Http::response([
                'server_types' => [
                    ['id' => 1, 'name' => 'cx22', 'locations' => [
                        ['id' => 1, 'name' => 'nbg1', 'available' => true, 'deprecation' => null],
                        ['id' => 2, 'name' => 'fsn1', 'available' => false, 'deprecation' => null],
                    ]],
                    ['id' => 2, 'name' => 'cpx11', 'locations' => [
                        ['id' => 2, 'name' => 'fsn1', 'available' => true, 'deprecation' => null],
                    ]],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/hetzner/server-types?location=fsn1&cloud_provider_token_uuid='.$this->hetznerToken->uuid);

        $response->assertStatus(200);
        expect(collect($response->json())->pluck('name')->all())->toBe(['cpx11']);
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
                    'status' => 'initializing',
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
            'hetzner_server_status' => 'initializing',
        ]);
    });

    test('validates the new server when it runs by default for tokens with the deploy permission', function () {
        fakeHetznerServerCreation();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->postJson('/api/v1/servers/hetzner', hetznerCreatePayload($this))
            ->assertStatus(201);

        Bus::assertDispatched(ValidateNewCloudServerJob::class);
    });

    test('does not validate the new server by default for tokens without the deploy permission', function () {
        fakeHetznerServerCreation();
        $writeToken = $this->user->createToken('write-only', ['write'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$writeToken])
            ->postJson('/api/v1/servers/hetzner', hetznerCreatePayload($this))
            ->assertStatus(201);

        Bus::assertNotDispatched(ValidateNewCloudServerJob::class);
    });

    test('rejects instant validation for tokens without the deploy permission before it creates the server', function () {
        Http::fake();
        $writeToken = $this->user->createToken('write-only', ['write'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$writeToken])
            ->postJson('/api/v1/servers/hetzner', [...hetznerCreatePayload($this), 'instant_validate' => true])
            ->assertStatus(403);

        Http::assertNothingSent();
        Bus::assertNotDispatched(ValidateNewCloudServerJob::class);
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

    test('stores the server address of the Hetzner IPv6 network', function () {
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
                        'ipv6' => ['ip' => '2a01:4f8:c016:bd23::/64'],
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
        $response->assertJsonFragment(['ip' => '2a01:4f8:c016:bd23::1']);
        $this->assertDatabaseHas('servers', [
            'hetzner_server_id' => '456',
            'ip' => '2a01:4f8:c016:bd23::1',
        ]);
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

describe('POST /api/v1/servers/hetzner upstream errors', function () {
    beforeEach(function () {
        $this->createServerRequest = fn () => $this->withHeaders([
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

        $this->fakeHetzner = fn (Closure $createServerResponse) => Http::fake(function (HttpRequest $request) use ($createServerResponse) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.hetzner.cloud/v1/ssh_keys')) {
                return Http::response(['ssh_keys' => [], 'meta' => ['pagination' => ['next_page' => null]]], 200);
            }
            if ($request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/ssh_keys') {
                return Http::response(['ssh_key' => ['id' => 123, 'fingerprint' => 'aa:bb:cc:dd']], 201);
            }
            if ($request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/servers') {
                return $createServerResponse();
            }
            if ($request->method() === 'DELETE' && $request->url() === 'https://api.hetzner.cloud/v1/servers/456') {
                return Http::response([], 200);
            }

            return Http::response([], 404);
        });
    });

    test('explains a read-only token without leaking the Hetzner message and does not retry', function () {
        ($this->fakeHetzner)(fn () => Http::response([
            'error' => ['code' => 'forbidden', 'message' => 'INTERNAL_LEAK_TOKEN_abc'],
        ], 403));

        $response = ($this->createServerRequest)();

        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'Failed to create Hetzner server. The Hetzner API token does not have permission for this request. Use a token with read and write access.',
            'provider_status' => 403,
            'provider_error_code' => 'forbidden',
        ]);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
        Http::assertSentCount(3);
        $this->assertDatabaseMissing('servers', ['name' => 'test-server']);
    });

    test('lists the invalid fields Hetzner reports', function () {
        ($this->fakeHetzner)(fn () => Http::response([
            'error' => [
                'code' => 'invalid_input',
                'message' => 'invalid input in field server_type',
                'details' => ['fields' => [['name' => 'server_type'], ['name' => '<script>']]],
            ],
        ], 422));

        $response = ($this->createServerRequest)();

        $response->assertStatus(502);
        $response->assertJson([
            'message' => 'Failed to create Hetzner server. Hetzner rejected the request as invalid. Check the selected values and your account limits. Invalid fields: server_type.',
            'provider_error_code' => 'invalid_input',
        ]);
    });

    test('does not send the create request again after a Hetzner server error', function () {
        ($this->fakeHetzner)(fn () => Http::response([
            'error' => ['code' => 'unavailable', 'message' => 'service unavailable'],
        ], 503));

        $response = ($this->createServerRequest)();

        $response->assertStatus(502);
        $response->assertJson(['message' => 'Failed to create Hetzner server. Hetzner cannot process the request now. Try again later.']);
        Http::assertSentCount(3);
    });

    test('deletes the Hetzner server when registration in Coolify fails', function () {
        ($this->fakeHetzner)(fn () => Http::response([
            'server' => ['id' => 456, 'name' => 'test-server', 'public_net' => []],
        ], 201));

        $response = ($this->createServerRequest)();

        $response->assertStatus(500);
        $response->assertExactJson(['message' => 'Failed to create Hetzner server.']);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.hetzner.cloud/v1/servers/456');
    });
});

describe('GET /api/v1/hetzner list endpoints upstream errors', function () {
    test('explains an invalid Hetzner token and does not retry it', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations*' => Http::response([
                'error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate'],
            ], 401),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/hetzner/locations?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'Failed to fetch Hetzner locations. The Hetzner API token is invalid or expired. Update the cloud provider token in Coolify.',
            'provider_status' => 401,
            'provider_error_code' => 'unauthorized',
        ]);
        Http::assertSentCount(1);
    });

    test('sends a read request again after a Hetzner server error', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations*' => Http::sequence()
                ->push(['error' => ['code' => 'unavailable', 'message' => 'service unavailable']], 503)
                ->push(['locations' => [['id' => 1, 'name' => 'nbg1']], 'meta' => ['pagination' => ['next_page' => null]]], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/hetzner/locations?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'nbg1']);
        Http::assertSentCount(2);
    });
});

describe('error responses do not leak exception details', function () {
    test('locations endpoint returns a safe 502 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc /var/secret/path'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/locations?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'Failed to fetch Hetzner locations. Hetzner cannot process the request now. Try again later.',
            'provider_status' => 500,
            'provider_error_code' => null,
        ]);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
        expect($response->getContent())->not->toContain('/var/secret/path');
    });

    test('server-types endpoint returns a safe 502 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/server_types*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/server-types?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'Failed to fetch Hetzner server types. Hetzner cannot process the request now. Try again later.',
            'provider_status' => 500,
            'provider_error_code' => null,
        ]);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });

    test('images endpoint returns a safe 502 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/images*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/images?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'Failed to fetch Hetzner images. Hetzner cannot process the request now. Try again later.',
            'provider_status' => 500,
            'provider_error_code' => null,
        ]);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });

    test('ssh-keys endpoint returns a safe 502 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/ssh-keys?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'Failed to fetch Hetzner SSH keys. Hetzner cannot process the request now. Try again later.',
            'provider_status' => 500,
            'provider_error_code' => null,
        ]);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });

    test('firewalls endpoint returns a safe 502 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/firewalls*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/firewalls?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'Failed to fetch Hetzner firewalls. Hetzner cannot process the request now. Try again later.',
            'provider_status' => 500,
            'provider_error_code' => null,
        ]);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });

    test('networks endpoint returns a safe 502 message on upstream failure', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/networks*' => Http::response([
                'error' => ['message' => 'INTERNAL_LEAK_TOKEN_abc'],
            ], 500),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/hetzner/networks?cloud_provider_token_id='.$this->hetznerToken->uuid);

        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'Failed to fetch Hetzner networks. Hetzner cannot process the request now. Try again later.',
            'provider_status' => 500,
            'provider_error_code' => null,
        ]);
        expect($response->getContent())->not->toContain('INTERNAL_LEAK_TOKEN_abc');
    });
});

function fakeHetznerServerCreation(): void
{
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
                'status' => 'initializing',
                'public_net' => ['ipv4' => ['ip' => '203.0.113.10']],
            ],
        ], 201),
    ]);
}

/**
 * @return array<string, mixed>
 */
function hetznerCreatePayload(object $test): array
{
    return [
        'cloud_provider_token_id' => $test->hetznerToken->uuid,
        'location' => 'nbg1',
        'server_type' => 'cx11',
        'image' => 15512617,
        'name' => 'test-server',
        'private_key_uuid' => $test->privateKey->uuid,
    ];
}
