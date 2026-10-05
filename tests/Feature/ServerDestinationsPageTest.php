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
        'server_role' => 'build',
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
        'server_role' => 'build',
        'is_build_server' => true,
    ]);

    StandaloneDocker::withoutEvents(fn () => $server->standaloneDockers()->delete());

    $this->get(route('server.destinations', ['server_uuid' => $server->uuid]))
        ->assertSuccessful()
        ->assertSee('Destinations')
        ->assertSee('No destinations')
        ->assertSee('Add a destination or scan the server for existing Docker networks.')
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
        ->assertDontSee('No destinations yet');
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
        ->assertSee('No destinations yet');
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

/**
 * Makes the user a member of a new team that owns a usable server, with the session on that team.
 */
function destinationsMemberTeamServer(User $user, bool $swarm = false): Server
{
    $memberTeam = Team::factory()->create();
    $user->teams()->attach($memberTeam, ['role' => 'member']);
    $user->load('teams');
    session(['currentTeam' => $memberTeam]);

    $server = Server::factory()->create(['team_id' => $memberTeam->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'is_swarm_manager' => $swarm]);

    return $server;
}

test('member cannot add a destination on the owning team server after switching to an owned team', function (bool $swarm) {
    $server = destinationsMemberTeamServer($this->user, $swarm);

    $component = Livewire::test(Destinations::class, ['server_uuid' => $server->uuid]);
    session(['currentTeam' => $this->team]);

    $component->call('add', 'customer-network')->assertForbidden();

    expect(StandaloneDocker::where('network', 'customer-network')->exists())->toBeFalse()
        ->and(SwarmDocker::where('network', 'customer-network')->exists())->toBeFalse();
})->with(['standalone' => false, 'swarm' => true]);

test('member cannot create a docker network on the owning team server after switching to an owned team', function () {
    $server = destinationsMemberTeamServer($this->user);

    $component = Livewire::test(Docker::class, ['server_id' => (string) $server->id]);
    session(['currentTeam' => $this->team]);

    $component->set('network', 'customer-network')
        ->call('submit')
        ->assertDispatched('error');

    expect(StandaloneDocker::where('network', 'customer-network')->exists())->toBeFalse();
});
