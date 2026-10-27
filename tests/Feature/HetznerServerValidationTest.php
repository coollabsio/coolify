<?php

use App\Actions\Server\ValidateServer;
use App\Livewire\Server\ValidateAndInstall;
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
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $token = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Test Hetzner Token',
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
        'cloud_provider_token_id' => $token->id,
        'hetzner_server_id' => 456,
        'hetzner_server_status' => 'initializing',
        'ip' => '1.2.3.4',
    ]);
});

function fakeHetznerServerStatus(string $status): void
{
    Http::fake([
        'https://api.hetzner.cloud/v1/servers/456' => Http::response([
            'server' => [
                'id' => 456,
                'status' => $status,
                'public_net' => ['ipv4' => ['ip' => '1.2.3.4']],
            ],
        ], 200),
    ]);
}

it('blocks action validation while the Hetzner server is still initializing', function () {
    fakeHetznerServerStatus('initializing');

    expect(fn () => ValidateServer::run($this->server))
        ->toThrow(Exception::class, 'Hetzner server is initializing. Wait until it is running, then validate.');

    expect($this->server->fresh()->validation_logs)
        ->toBe('Hetzner server is initializing. Wait until it is running, then validate.');
});

it('blocks validation modal connection checks when the Hetzner server is off', function () {
    fakeHetznerServerStatus('off');

    Livewire::test(ValidateAndInstall::class, ['server' => $this->server])
        ->call('validateConnection')
        ->assertSet('error', 'Hetzner server is off. Power it on before validating.')
        ->assertNotDispatched('validateOS');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'hetzner_server_status' => 'off',
        'validation_logs' => 'Hetzner server is off. Power it on before validating.',
    ]);
});
