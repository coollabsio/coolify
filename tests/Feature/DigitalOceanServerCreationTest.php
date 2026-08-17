<?php

use App\Livewire\Server\New\ByDigitalOcean;
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

    $this->digitalOceanToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'digitalocean',
        'token' => 'test-digitalocean-token',
        'name' => 'Test DigitalOcean Token',
    ]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

function submitDigitalOceanServer(): void
{
    Livewire::test(ByDigitalOcean::class, ['selectedTokenUuid' => test()->digitalOceanToken->uuid])
        ->assertSet('current_step', 2)
        ->set('server_name', 'test-do-server')
        ->set('selected_region', 'nyc1')
        ->set('selected_size', 's-1vcpu-1gb')
        ->set('selected_image', 'ubuntu-24-04-x64')
        ->set('private_key_id', test()->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();
}

it('persists the server with a placeholder IP when waiting for the droplet IP fails', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account/keys' => Http::response([
            'ssh_key' => ['id' => 123],
        ], 201),
        'https://api.digitalocean.com/v2/account/keys*' => Http::response([
            'ssh_keys' => [],
        ], 200),
        'https://api.digitalocean.com/v2/droplets' => Http::response([
            'droplet' => ['id' => 555, 'status' => 'new'],
        ], 202),
        'https://api.digitalocean.com/v2/droplets/555' => Http::response(['message' => 'server error'], 500),
    ]);

    submitDigitalOceanServer();

    $this->assertDatabaseHas('servers', [
        'name' => 'test-do-server',
        'ip' => Server::PLACEHOLDER_IP,
        'team_id' => $this->team->id,
        'cloud_provider_token_id' => $this->digitalOceanToken->id,
        'digitalocean_droplet_id' => '555',
        'digitalocean_droplet_status' => 'new',
    ]);
});

it('updates the placeholder IP once the droplet reports one', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account/keys' => Http::response([
            'ssh_key' => ['id' => 123],
        ], 201),
        'https://api.digitalocean.com/v2/account/keys*' => Http::response([
            'ssh_keys' => [],
        ], 200),
        'https://api.digitalocean.com/v2/droplets' => Http::response([
            'droplet' => ['id' => 555, 'status' => 'new'],
        ], 202),
        'https://api.digitalocean.com/v2/droplets/555' => Http::response([
            'droplet' => [
                'id' => 555,
                'status' => 'active',
                'networks' => [
                    'v4' => [
                        ['type' => 'public', 'ip_address' => '203.0.113.40'],
                    ],
                ],
            ],
        ], 200),
    ]);

    submitDigitalOceanServer();

    $this->assertDatabaseHas('servers', [
        'name' => 'test-do-server',
        'ip' => '203.0.113.40',
        'digitalocean_droplet_id' => '555',
        'digitalocean_droplet_status' => 'active',
    ]);
});

it('deletes the droplet when local server persistence fails', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account/keys' => Http::response([
            'ssh_key' => ['id' => 123],
        ], 201),
        'https://api.digitalocean.com/v2/account/keys*' => Http::response([
            'ssh_keys' => [],
        ], 200),
        'https://api.digitalocean.com/v2/droplets' => Http::response([
            'droplet' => [
                'id' => 555,
                'status' => 'active',
                'networks' => [
                    'v4' => [
                        ['type' => 'public', 'ip_address' => '203.0.113.40'],
                    ],
                ],
            ],
        ], 202),
        'https://api.digitalocean.com/v2/droplets/555' => Http::response(null, 204),
    ]);

    $eventDispatcher = Server::getEventDispatcher();
    Server::setEventDispatcher(clone $eventDispatcher);
    Server::created(function (): void {
        throw new RuntimeException('local persistence failed');
    });

    try {
        Livewire::test(ByDigitalOcean::class, ['selectedTokenUuid' => $this->digitalOceanToken->uuid])
            ->set('server_name', 'persistence-fails')
            ->set('selected_region', 'nyc1')
            ->set('selected_size', 's-1vcpu-1gb')
            ->set('selected_image', 'ubuntu-24-04-x64')
            ->set('private_key_id', $this->privateKey->id)
            ->call('submit')
            ->assertDispatched('error', 'local persistence failed');
    } finally {
        Server::setEventDispatcher($eventDispatcher);
    }

    $this->assertDatabaseMissing('servers', [
        'digitalocean_droplet_id' => 555,
    ]);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.digitalocean.com/v2/droplets/555');
});

it('renders only the full width buy button at the bottom of the DigitalOcean form', function () {
    Livewire::test(ByDigitalOcean::class)
        ->set('current_step', 2)
        ->assertDontSee('wire:click="previousStep"', false)
        ->assertSeeHtml('class="button w-full"')
        ->assertSee('Buy & Create Server', false);
});
