<?php

use App\Livewire\Server\New\ByHetzner;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->hetznerToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Test Hetzner Token',
    ]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

it('persists the server with a placeholder IP when Hetzner has not assigned one yet', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
            'ssh_key' => ['id' => 42, 'fingerprint' => 'ff:ff'],
        ], 201),
        'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
            'ssh_keys' => [],
        ], 200),
        'https://api.hetzner.cloud/v1/servers' => Http::response([
            'server' => [
                'id' => 777,
                'status' => 'initializing',
                'public_net' => [
                    'ipv4' => null,
                    'ipv6' => null,
                ],
            ],
        ], 201),
    ]);

    Livewire::test(ByHetzner::class, ['selectedTokenUuid' => $this->hetznerToken->uuid])
        ->assertSet('current_step', 2)
        ->set('server_name', 'test-hetzner-server')
        ->set('selected_location', 'fsn1')
        ->set('selected_server_type', 'cx22')
        ->set('selected_image', 114690387)
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('servers', [
        'name' => 'test-hetzner-server',
        'ip' => Server::PLACEHOLDER_IP,
        'team_id' => $this->team->id,
        'cloud_provider_token_id' => $this->hetznerToken->id,
        'hetzner_server_id' => '777',
        'hetzner_server_status' => 'initializing',
    ]);
});

it('creates the server with the real IP when Hetzner assigns one immediately', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
            'ssh_key' => ['id' => 42, 'fingerprint' => 'ff:ff'],
        ], 201),
        'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
            'ssh_keys' => [],
        ], 200),
        'https://api.hetzner.cloud/v1/servers' => Http::response([
            'server' => [
                'id' => 777,
                'status' => 'running',
                'public_net' => [
                    'ipv4' => ['ip' => '203.0.113.30'],
                ],
            ],
        ], 201),
    ]);

    Livewire::test(ByHetzner::class, ['selectedTokenUuid' => $this->hetznerToken->uuid])
        ->assertSet('current_step', 2)
        ->set('server_name', 'test-hetzner-server')
        ->set('selected_location', 'fsn1')
        ->set('selected_server_type', 'cx22')
        ->set('selected_image', 114690387)
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('servers', [
        'name' => 'test-hetzner-server',
        'ip' => '203.0.113.30',
        'hetzner_server_id' => '777',
        'hetzner_server_status' => 'running',
    ]);
});
