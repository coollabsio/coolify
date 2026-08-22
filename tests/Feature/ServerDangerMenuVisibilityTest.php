<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

test('danger menu is visible for non-coolify-host servers that use host.docker.internal', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'name' => 'lima-ubuntu-2404',
        'ip' => 'host.docker.internal',
        'port' => 2222,
    ])->fresh();

    expect($server->getAttributes()['ip'])->toBe('host.docker.internal')
        ->and($server->isLocalhost())->toBeTrue()
        ->and($server->is_coolify_host)->toBeFalse();

    $this->get(route('server.show', ['server_uuid' => $server->uuid]))
        ->assertSuccessful()
        ->assertSee('>Danger</', false)
        ->assertSee(route('server.delete', ['server_uuid' => $server->uuid]), false);
});

test('danger menu is hidden for the coolify host server', function () {
    $server = Server::factory()->create([
        'id' => 0,
        'uuid' => 'localhost',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'name' => 'localhost',
        'ip' => 'host.docker.internal',
    ])->fresh();

    expect($server->isLocalhost())->toBeTrue()
        ->and($server->is_coolify_host)->toBeTrue();

    $this->get(route('server.show', ['server_uuid' => $server->uuid]))
        ->assertSuccessful()
        ->assertDontSee('>Danger</', false)
        ->assertDontSee(route('server.delete', ['server_uuid' => $server->uuid]), false);
});

test('danger page is reachable for host.docker.internal non-coolify-host servers', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'name' => 'lima-ubuntu-2404',
        'ip' => 'host.docker.internal',
        'port' => 2222,
    ]);

    $this->get(route('server.delete', ['server_uuid' => $server->uuid]))
        ->assertSuccessful()
        ->assertSee('Delete server')
        ->assertSee('Delete '.$server->name);
});
