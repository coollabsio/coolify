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
    Http::preventStrayRequests();

    InstanceSettings::query()->whereKey(0)->delete();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;
    $this->hostingerToken = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hostinger',
        'token' => 'hostinger-api-token',
    ]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

it('gets Hostinger data centers', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/data-centers' => Http::response([
            ['id' => 19, 'city' => 'Amsterdam', 'location' => 'nl'],
        ]),
    ]);

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/data-centers?cloud_provider_token_id='.$this->hostingerToken->uuid)
        ->assertSuccessful()
        ->assertJsonFragment(['id' => 19, 'city' => 'Amsterdam']);
});

it('gets Hostinger SSH keys and post-install scripts', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/public-keys' => Http::response([
            'data' => [['id' => 42, 'name' => 'Operations']],
        ]),
        'https://developers.hostinger.com/api/vps/v1/post-install-scripts' => Http::response([
            'data' => [['id' => 73, 'name' => 'Bootstrap Coolify']],
        ]),
    ]);

    $query = '?cloud_provider_token_id='.$this->hostingerToken->uuid;

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/ssh-keys'.$query)
        ->assertSuccessful()
        ->assertJsonFragment(['id' => 42, 'name' => 'Operations']);

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/post-install-scripts'.$query)
        ->assertSuccessful()
        ->assertJsonFragment(['id' => 73, 'name' => 'Bootstrap Coolify']);
});

it('creates a Hostinger VPS server through the API', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'order' => ['id' => 2957086, 'status' => 'completed'],
            'virtual_machine' => [
                'id' => 17923,
                'state' => 'creating',
                'ipv4' => [['address' => '203.0.113.10']],
            ],
        ]),
    ]);

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/servers/hostinger', [
            'cloud_provider_token_id' => $this->hostingerToken->uuid,
            'item_id' => 'hostingercom-vps-kvm2-usd-1m',
            'data_center_id' => 19,
            'template_id' => 1130,
            'name' => 'api-hostinger.example.com',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_backups' => true,
        ])
        ->assertCreated()
        ->assertJsonFragment([
            'hostinger_virtual_machine_id' => 17923,
            'ip' => '203.0.113.10',
        ]);

    $this->assertDatabaseHas('servers', [
        'team_id' => $this->team->id,
        'hostinger_virtual_machine_id' => 17923,
        'ip' => '203.0.113.10',
    ]);
});

it('reports a Hostinger order that is waiting for payment through the API', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'id' => 2957086,
            'status' => 'payment_initiated',
        ], 202),
    ]);

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/servers/hostinger', [
            'cloud_provider_token_id' => $this->hostingerToken->uuid,
            'item_id' => 'hostingercom-vps-kvm2-usd-1m',
            'data_center_id' => 19,
            'template_id' => 1130,
            'private_key_uuid' => $this->privateKey->uuid,
        ])
        ->assertStatus(202)
        ->assertJsonFragment(['message' => 'Hostinger order 2957086 is waiting for payment. Finish the VPS setup in hPanel.']);

    $this->assertDatabaseMissing('servers', ['team_id' => $this->team->id]);
});

it('accepts Hostinger cloud provider tokens through the API', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([]),
    ]);

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/cloud-tokens', [
            'provider' => 'hostinger',
            'token' => 'another-hostinger-token',
            'name' => 'Another Hostinger Token',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('cloud_provider_tokens', [
        'team_id' => $this->team->id,
        'provider' => 'hostinger',
        'name' => 'Another Hostinger Token',
    ]);
});
