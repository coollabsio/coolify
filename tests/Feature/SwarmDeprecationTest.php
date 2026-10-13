<?php

use App\Livewire\Destination\New\Docker;
use App\Livewire\Server\Swarm;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('a team without swarm cannot enable it on a server', function () {
    Livewire::test(Swarm::class, ['server_uuid' => $this->server->uuid])
        ->set('isSwarmManager', true)
        ->call('instantSave')
        ->assertDispatched('error', 'Docker Swarm is deprecated and cannot be enabled for new teams.');

    expect($this->server->settings->fresh()->is_swarm_manager)->toBeFalsy();
});

test('a team already using swarm can manage another server role', function () {
    $existingSwarmServer = Server::factory()->create(['team_id' => $this->team->id]);
    $existingSwarmServer->settings()->update(['is_swarm_manager' => true]);

    Livewire::test(Swarm::class, ['server_uuid' => $this->server->uuid])
        ->set('isSwarmWorker', true)
        ->call('instantSave')
        ->assertDispatched('success', 'Swarm settings updated.');

    expect($this->server->settings->fresh()->is_swarm_worker)->toBeTruthy();
});

test('the swarm page shows the deprecation notice for an existing swarm team', function () {
    $this->server->settings()->update(['is_swarm_manager' => true]);

    Livewire::test(Swarm::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Docker Swarm support is deprecated')
        ->assertSee(config('deprecations.swarm'));
});

test('regular teams do not see swarm setup in server navigation', function () {
    $swarmUrl = route('server.swarm', ['server_uuid' => $this->server->uuid]);

    $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))
        ->assertSuccessful()
        ->assertDontSee($swarmUrl, false);

    $this->server->settings()->update(['is_swarm_manager' => true]);

    $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))
        ->assertSuccessful()
        ->assertSee($swarmUrl, false);
});

test('existing swarm destination pages show the deprecation notice', function () {
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_manager' => true,
    ]);
    $destination = SwarmDocker::query()->create([
        'name' => 'Legacy Swarm',
        'network' => 'legacy-overlay',
        'server_id' => $this->server->id,
    ]);

    $this->get(route('server.destinations', ['server_uuid' => $this->server->uuid]))
        ->assertSuccessful()
        ->assertSee(config('deprecations.swarm'));

    $this->get(route('destination.show', ['destination_uuid' => $destination->uuid]))
        ->assertSuccessful()
        ->assertSee(config('deprecations.swarm'));
});

test('destination creation derives swarm mode from the existing server', function () {
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_manager' => true,
    ]);

    Livewire::test(Docker::class, ['server_id' => (string) $this->server->id])
        ->set('name', 'Existing Swarm Network')
        ->set('network', 'existing-overlay')
        ->call('submit')
        ->assertHasNoErrors();

    expect(SwarmDocker::query()
        ->whereBelongsTo($this->server)
        ->where('network', 'existing-overlay')
        ->exists())->toBeTrue();
});
