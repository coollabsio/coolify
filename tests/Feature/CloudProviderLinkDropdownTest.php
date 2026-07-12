<?php

use App\Livewire\Server\Show;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '1.2.3.4',
    ]);
});

it('shows link cloud provider dropdown with available unlinked providers', function () {
    CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Test Hetzner Token',
    ]);

    CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'vultr',
        'token' => 'test-vultr-token',
        'name' => 'Test Vultr Token',
    ]);

    CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'digitalocean',
        'token' => 'test-digitalocean-token',
        'name' => 'Test DigitalOcean Token',
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Link Cloud Provider')
        ->assertSee('Hetzner')
        ->assertSee('DigitalOcean')
        ->assertSee('Vultr')
        ->assertSee('Hetzner Token')
        ->assertSee('DigitalOcean Token')
        ->assertSee('Vultr Token')
        ->assertSee('Server ID')
        ->assertSee('Droplet ID')
        ->assertSee('Instance ID')
        ->assertSee('Search by IP')
        ->assertSee('Search');
});

it('hides link cloud provider dropdown when no providers can be linked', function () {
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertDontSee('Link Cloud Provider');
});

it('does not list providers already linked to the server', function () {
    CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Test Hetzner Token',
    ]);

    CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'vultr',
        'token' => 'test-vultr-token',
        'name' => 'Test Vultr Token',
    ]);

    $this->server->update(['hetzner_server_id' => 123]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Link Cloud Provider')
        ->assertSee('Vultr Token')
        ->assertDontSee('Hetzner Token');
});

it('shows Hetzner search by IP errors in the modal', function () {
    $token = CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'invalid-hetzner-token',
        'name' => 'Invalid Hetzner Token',
    ]);

    Http::fake([
        'https://api.hetzner.cloud/v1/servers*' => Http::response([
            'error' => ['message' => 'invalid token'],
        ], 401),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedHetznerTokenId', $token->id)
        ->call('searchHetznerServer')
        ->assertSet('hetznerSearchError', fn (string $error) => str_contains($error, 'Failed to search Hetzner servers:') && str_contains($error, 'invalid token'))
        ->assertSee('Failed to search Hetzner servers:')
        ->assertSee('invalid token');
});

it('shows Hetzner search by ID errors in the modal', function () {
    $token = CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'invalid-hetzner-token',
        'name' => 'Invalid Hetzner Token',
    ]);

    Http::fake([
        'https://api.hetzner.cloud/v1/servers/12345678' => Http::response([
            'error' => ['message' => 'invalid token'],
        ], 401),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedHetznerTokenId', $token->id)
        ->set('manualHetznerServerId', '12345678')
        ->call('searchHetznerServerById')
        ->assertSet('hetznerSearchError', fn (string $error) => str_contains($error, 'Failed to fetch Hetzner server:') && str_contains($error, 'invalid token'))
        ->assertSee('Failed to fetch Hetzner server:')
        ->assertSee('invalid token');
});

it('shows DigitalOcean search errors in the modal', function () {
    $token = CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'digitalocean',
        'token' => 'invalid-digitalocean-token',
        'name' => 'Invalid DigitalOcean Token',
    ]);

    Http::fake([
        'https://api.digitalocean.com/v2/droplets*' => Http::response([
            'message' => 'invalid token',
        ], 401),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedDigitalOceanTokenId', $token->id)
        ->call('searchDigitalOceanDroplet')
        ->assertSet('digitalOceanSearchError', fn (string $error) => str_contains($error, 'Failed to search DigitalOcean droplets:') && str_contains($error, 'invalid token'))
        ->assertSee('Failed to search DigitalOcean droplets:')
        ->assertSee('invalid token');
});

it('shows Vultr search errors in the modal', function () {
    $token = CloudProviderToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'vultr',
        'token' => 'invalid-vultr-token',
        'name' => 'Invalid Vultr Token',
    ]);

    Http::fake([
        'https://api.vultr.com/v2/instances*' => Http::response([
            'error' => 'invalid token',
        ], 401),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedVultrTokenId', $token->id)
        ->call('searchVultrInstance')
        ->assertSet('vultrSearchError', fn (string $error) => str_contains($error, 'Failed to search Vultr instances:') && str_contains($error, 'invalid token'))
        ->assertSee('Failed to search Vultr instances:')
        ->assertSee('invalid token');
});
