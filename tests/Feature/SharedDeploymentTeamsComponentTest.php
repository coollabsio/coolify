<?php

use App\Livewire\Server\SharedDeploymentTeams;
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

    $this->deploymentServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $this->deploymentServer->settings()->update([
        'is_build_server' => false,
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    $this->actingAs($this->instanceAdmin);
    session(['currentTeam' => $this->rootTeam]);
});

test('instance admin can mount shared deployment team management', function () {
    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $this->deploymentServer,
    ])
        ->assertOk()
        ->assertSet("teamAccess.{$this->teamA->id}", false)
        ->assertSet("teamAccess.{$this->teamB->id}", false);
});

test('instance admin can share a deployment server with selected teams', function () {
    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $this->deploymentServer,
    ])
        ->set("teamAccess.{$this->teamA->id}", true)
        ->set("teamAccess.{$this->teamB->id}", false)
        ->call('save')
        ->assertDispatched('success');

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->teamA->id,
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->teamB->id,
    ]);
});

test('saving synchronizes removed deployment access', function () {
    $this->deploymentServer->sharedTeams()->attach([
        $this->teamA->id => [
            'can_build' => false,
            'can_deploy' => true,
        ],
        $this->teamB->id => [
            'can_build' => false,
            'can_deploy' => true,
        ],
    ]);

    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $this->deploymentServer,
    ])
        ->set("teamAccess.{$this->teamA->id}", false)
        ->set("teamAccess.{$this->teamB->id}", true)
        ->call('save')
        ->assertDispatched('success');

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->teamA->id,
    ]);

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->teamB->id,
        'can_build' => false,
        'can_deploy' => true,
    ]);
});

test('saving deployment access preserves an existing build grant', function () {
    $this->deploymentServer->sharedTeams()->attach($this->teamA->id, [
        'can_build' => true,
        'can_deploy' => false,
    ]);

    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $this->deploymentServer,
    ])
        ->set("teamAccess.{$this->teamA->id}", true)
        ->call('save')
        ->assertDispatched('success');

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->teamA->id,
        'can_build' => true,
        'can_deploy' => true,
    ]);
});

test('removing deployment access does not remove an existing build grant', function () {
    $this->deploymentServer->sharedTeams()->attach($this->teamA->id, [
        'can_build' => true,
        'can_deploy' => true,
    ]);

    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $this->deploymentServer,
    ])
        ->set("teamAccess.{$this->teamA->id}", false)
        ->call('save')
        ->assertDispatched('success');

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->teamA->id,
        'can_build' => true,
        'can_deploy' => false,
    ]);
});

test('owner team and unknown ids cannot be injected into deployment sharing', function () {
    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $this->deploymentServer,
    ])
        ->set('teamAccess', [
            $this->ownerTeam->id => true,
            999999 => true,
            $this->teamA->id => true,
        ])
        ->call('save')
        ->assertDispatched('success');

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->ownerTeam->id,
    ]);

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => 999999,
    ]);

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->teamA->id,
        'can_deploy' => true,
    ]);
});

test('sharing cannot be changed after server enters build mode', function () {
    $this->deploymentServer->sharedTeams()->attach($this->teamA->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $component = Livewire::test(SharedDeploymentTeams::class, [
        'server' => $this->deploymentServer,
    ]);

    $this->deploymentServer->settings()->update([
        'is_build_server' => true,
    ]);

    $component
        ->set("teamAccess.{$this->teamA->id}", false)
        ->call('save')
        ->assertDispatched('error');

    $this->assertDatabaseHas('server_team', [
        'server_id' => $this->deploymentServer->id,
        'team_id' => $this->teamA->id,
        'can_deploy' => true,
    ]);
});

test('regular team owner cannot mount shared deployment management', function () {
    $regularUser = User::factory()->create();
    $regularUser->teams()->attach($this->ownerTeam, [
        'role' => 'owner',
    ]);
    $regularUser->load('teams');

    $this->actingAs($regularUser);
    session(['currentTeam' => $this->ownerTeam]);

    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $this->deploymentServer,
    ])->assertForbidden();
});

test('component cannot mount for a build server', function () {
    $buildServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $buildServer->settings()->update([
        'is_build_server' => true,
    ]);

    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $buildServer,
    ])->assertNotFound();
});

test('component cannot mount for localhost', function () {
    $localhost = Server::factory()->create([
        'id' => 0,
        'team_id' => $this->rootTeam->id,
        'ip' => 'host.docker.internal',
    ]);

    $localhost->settings()->update([
        'is_build_server' => false,
    ]);

    Livewire::test(SharedDeploymentTeams::class, [
        'server' => $localhost,
    ])->assertNotFound();
});
