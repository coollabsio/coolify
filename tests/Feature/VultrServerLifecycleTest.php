<?php

use App\Actions\Server\ValidateServer as ValidateServerAction;
use App\Livewire\Server\Show;
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

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Private Key',
        'description' => 'Test private key',
        'private_key' => vultrLifecycleTestPrivateKey(),
    ]);

    $this->vultrToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'vultr',
        'token' => 'test-vultr-token',
        'name' => 'Test Vultr Token',
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'cloud_provider_token_id' => $this->vultrToken->id,
        'vultr_instance_id' => 'instance-1',
        'vultr_instance_status' => 'pending',
        'ip' => '1.2.3.4',
    ]);
});

function vultrLifecycleTestPrivateKey(): string
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

it('refreshes Vultr instance status', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'status' => 'active',
            ],
        ], 200),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('checkVultrInstanceStatus', true)
        ->assertSet('vultrInstanceStatus', 'active');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'vultr_instance_status' => 'active',
    ]);
});

it('refreshes Vultr status on server page load even when cached status is active', function () {
    $this->server->update(['vultr_instance_status' => 'active']);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('$wire.checkVultrInstanceStatus();', false);
});

it('updates placeholder server IP when Vultr status refresh returns assigned public IP', function () {
    $this->server->update(['ip' => '0.0.0.0']);

    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'status' => 'active',
                'main_ip' => '9.8.7.6',
            ],
        ], 200),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('checkVultrInstanceStatus', true)
        ->assertSet('ip', '9.8.7.6');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'ip' => '9.8.7.6',
        'vultr_instance_status' => 'active',
    ]);
});

it('does not overwrite an established server IP during Vultr status refresh', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'status' => 'active',
                'main_ip' => '9.8.7.6',
            ],
        ], 200),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('checkVultrInstanceStatus', true)
        ->assertSet('ip', '1.2.3.4')
        ->assertSet('vultrInstanceStatus', 'active');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'ip' => '1.2.3.4',
        'vultr_instance_status' => 'active',
    ]);
});

it('blocks server page validation when Vultr instance is stopped', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'status' => 'active',
                'power_status' => 'stopped',
                'main_ip' => '1.2.3.4',
            ],
        ], 200),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('validateServer')
        ->assertSet('vultrInstanceStatus', 'stopped')
        ->assertDispatched('error', 'Vultr instance is stopped. Power it on before validating.')
        ->assertNotDispatched('init');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'vultr_instance_status' => 'stopped',
    ]);

    expect($this->server->fresh()->validation_logs)->toBeNull();
});

it('marks missing Vultr instances as deleted before validation', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([
            'error' => 'instance not found',
        ], 404),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('validateServer')
        ->assertSet('vultrInstanceStatus', 'deleted')
        ->assertDispatched('error', 'Vultr instance is deleted or no longer accessible. Relink this server before validating.')
        ->assertNotDispatched('init');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'vultr_instance_status' => 'deleted',
    ]);
});

it('blocks validation modal connection checks when Vultr instance is stopped', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'status' => 'active',
                'power_status' => 'stopped',
                'main_ip' => '1.2.3.4',
            ],
        ], 200),
    ]);

    Livewire::test(ValidateAndInstall::class, ['server' => $this->server])
        ->call('validateConnection')
        ->assertSet('error', 'Vultr instance is stopped. Power it on before validating.')
        ->assertNotDispatched('validateOS');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'vultr_instance_status' => 'stopped',
        'validation_logs' => 'Vultr instance is stopped. Power it on before validating.',
    ]);
});

it('blocks action validation when Vultr instance is stopped', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'status' => 'active',
                'power_status' => 'stopped',
                'main_ip' => '1.2.3.4',
            ],
        ], 200),
    ]);

    expect(fn () => ValidateServerAction::run($this->server))
        ->toThrow(Exception::class, 'Vultr instance is stopped. Power it on before validating.');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'vultr_instance_status' => 'stopped',
        'validation_logs' => 'Vultr instance is stopped. Power it on before validating.',
    ]);
});

it('starts a Vultr instance', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1/start' => Http::response([], 204),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('startVultrInstance')
        ->assertSet('vultrInstanceStatus', 'starting');

    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id,
        'vultr_instance_status' => 'starting',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.vultr.com/v2/instances/instance-1/start');
});

it('links a server to Vultr by matching IP', function () {
    $unlinkedServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '5.6.7.8',
    ]);

    Http::fake([
        'https://api.vultr.com/v2/instances?per_page=100' => Http::response([
            'instances' => [
                [
                    'id' => 'instance-2',
                    'label' => 'matched-server',
                    'main_ip' => '5.6.7.8',
                    'status' => 'active',
                    'plan' => 'vc2-1c-1gb',
                ],
            ],
            'meta' => ['links' => ['next' => null]],
        ], 200),
        'https://api.vultr.com/v2/instances/instance-2' => Http::response([
            'instance' => [
                'id' => 'instance-2',
                'status' => 'active',
            ],
        ], 200),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $unlinkedServer->uuid])
        ->set('selectedVultrTokenId', $this->vultrToken->id)
        ->call('searchVultrInstance')
        ->assertSet('matchedVultrInstance.id', 'instance-2')
        ->call('linkToVultr');

    $this->assertDatabaseHas('servers', [
        'id' => $unlinkedServer->id,
        'cloud_provider_token_id' => $this->vultrToken->id,
        'vultr_instance_id' => 'instance-2',
        'vultr_instance_status' => 'active',
    ]);
});
