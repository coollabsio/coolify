<?php

use App\Livewire\Destination\New\Docker;
use App\Livewire\Server\Destinations;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('destination creation modal can mount with selected team server even when global usable server list excludes it', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => true,
    ]);

    StandaloneDocker::withoutEvents(fn () => $server->standaloneDockers()->delete());

    Livewire::test(Docker::class, ['server_id' => (string) $server->id])
        ->assertSet('selectedServer.id', $server->id)
        ->assertSet('serverId', (string) $server->id);
});

test('server destinations page renders when selected server has no destinations', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => true,
    ]);

    StandaloneDocker::withoutEvents(fn () => $server->standaloneDockers()->delete());

    $this->get(route('server.destinations', ['server_uuid' => $server->uuid]))
        ->assertSuccessful()
        ->assertSee('Destinations')
        ->assertSee('No destinations configured for this server yet.')
        ->assertDontSee('Server not found.');
});

test('global destinations page does not render per-server empty states beside existing destinations', function () {
    $serverWithDestination = Server::factory()->create(['team_id' => $this->team->id]);
    $serverWithDestination->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $serverWithoutDestination = Server::factory()->create(['team_id' => $this->team->id]);
    $serverWithoutDestination->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    StandaloneDocker::withoutEvents(fn () => $serverWithoutDestination->standaloneDockers()->delete());

    $this->get(route('destination.index'))
        ->assertSuccessful()
        ->assertSee($serverWithDestination->standaloneDockers()->first()->name)
        ->assertDontSee('No destinations found.');
});

test('global destinations page renders a single empty state when no usable servers have destinations', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    StandaloneDocker::withoutEvents(fn () => $server->standaloneDockers()->delete());

    $this->get(route('destination.index'))
        ->assertSuccessful()
        ->assertSee('No destinations found.');
});

test('adding a discovered swarm destination stores the selected network name', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_manager' => true,
    ]);

    Livewire::test(Destinations::class, ['server_uuid' => $server->uuid])
        ->call('add', 'customer-network');

    expect(SwarmDocker::where('server_id', $server->id)->where('network', 'customer-network')->exists())->toBeTrue();
});
