<?php

use App\Livewire\Server\SharedBuildTeams;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(
        fn () => InstanceSettings::firstOrCreate(['id' => 0])
    );

    $this->rootTeam = Team::factory()->create(['id' => 0]);

    $this->instanceAdmin = User::factory()->create();
    $this->instanceAdmin->teams()->attach($this->rootTeam, [
        'role' => 'admin',
    ]);
    $this->instanceAdmin->load('teams');

    $this->ownerTeam = Team::factory()->create();
    $this->teamA = Team::factory()->create([
        'name' => 'Team A',
    ]);
    $this->teamB = Team::factory()->create([
        'name' => 'Team B',
    ]);

    $this->buildServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $this->buildServer->settings()->update([
        'is_build_server' => true,
        'is_reachable' => true,
    ]);

    $this->actingAs($this->instanceAdmin);
    session(['currentTeam' => $this->rootTeam]);
});

test('instance admin can mount shared build team management', function () {
    Livewire::test(SharedBuildTeams::class, [
        'server' => $this->buildServer,
    ])
        ->assertOk()
        ->assertSet("teamAccess.{$this->teamA->id}", false)
        ->assertSet("teamAccess.{$this->teamB->id}", false);
});

test('instance admin can share a build server with selected teams', function () {
    Livewire::test(SharedBuildTeams::class, [
        'server' => $this->buildServer,
    ])
        ->set("teamAccess.{$this->teamA->id}", true)
        ->set("teamAccess.{$this->teamB->id}", false)
        ->call('save')
        ->assertDispatched('success');

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->buildServer->id,
        'team_id' => $this->teamA->id,
        'can_build' => true,
    ]);

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $this->buildServer->id,
        'team_id' => $this->teamB->id,
    ]);
});

test('saving synchronizes removed team access', function () {
    $this->buildServer->sharedTeams()->attach([
        $this->teamA->id => ['can_build' => true],
        $this->teamB->id => ['can_build' => true],
    ]);

    Livewire::test(SharedBuildTeams::class, [
        'server' => $this->buildServer,
    ])
        ->set("teamAccess.{$this->teamA->id}", false)
        ->set("teamAccess.{$this->teamB->id}", true)
        ->call('save')
        ->assertDispatched('success');

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $this->buildServer->id,
        'team_id' => $this->teamA->id,
    ]);

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->buildServer->id,
        'team_id' => $this->teamB->id,
        'can_build' => true,
    ]);
});

test('owner team and unknown team ids cannot be injected into sharing', function () {
    Livewire::test(SharedBuildTeams::class, [
        'server' => $this->buildServer,
    ])
        ->set('teamAccess', [
            $this->ownerTeam->id => true,
            999999 => true,
            $this->teamA->id => true,
        ])
        ->call('save')
        ->assertDispatched('success');

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $this->buildServer->id,
        'team_id' => $this->ownerTeam->id,
    ]);

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $this->buildServer->id,
        'team_id' => 999999,
    ]);

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->buildServer->id,
        'team_id' => $this->teamA->id,
        'can_build' => true,
    ]);
});

test('sharing cannot be changed after server leaves build mode', function () {
    $this->buildServer->sharedTeams()->attach($this->teamA, [
        'can_build' => true,
    ]);

    $component = Livewire::test(SharedBuildTeams::class, [
        'server' => $this->buildServer,
    ]);

    $this->buildServer->settings()->update([
        'is_build_server' => false,
    ]);

    $component
        ->set("teamAccess.{$this->teamA->id}", false)
        ->call('save')
        ->assertDispatched('error');

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->buildServer->id,
        'team_id' => $this->teamA->id,
        'can_build' => true,
    ]);
});

test('regular team owner cannot mount shared build team management', function () {
    $regularUser = User::factory()->create();
    $regularUser->teams()->attach($this->ownerTeam, [
        'role' => 'owner',
    ]);
    $regularUser->load('teams');

    $this->actingAs($regularUser);
    session(['currentTeam' => $this->ownerTeam]);

    Livewire::test(SharedBuildTeams::class, [
        'server' => $this->buildServer,
    ])->assertForbidden();
});

test('component cannot mount for a deployment server', function () {
    $deploymentServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $deploymentServer->settings()->update([
        'is_build_server' => false,
    ]);

    Livewire::test(SharedBuildTeams::class, [
        'server' => $deploymentServer,
    ])->assertNotFound();
});
