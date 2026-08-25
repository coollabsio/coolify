<?php

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ownerTeam = Team::factory()->create();
    $this->sharedTeam = Team::factory()->create();
    $this->unrelatedTeam = Team::factory()->create();

    $this->sharedUser = User::factory()->create();
    $this->sharedUser->teams()->attach($this->sharedTeam, [
        'role' => 'owner',
    ]);
    $this->sharedUser->load('teams');

    $this->deploymentServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $this->deploymentServer->settings()->update([
        'is_build_server' => false,
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);

    $this->destination = $this->deploymentServer
        ->standaloneDockers()
        ->firstOrFail();
});

test('owner team can deploy to its own server', function () {
    expect(
        Server::deployableByTeam($this->ownerTeam->id)
            ->pluck('servers.id')
    )->toContain($this->deploymentServer->id);
});

test('shared team can deploy when deployment access is enabled', function () {
    $this->deploymentServer->sharedTeams()->attach(
        $this->sharedTeam->id,
        [
            'can_build' => false,
            'can_deploy' => true,
        ]
    );

    expect(
        Server::deployableByTeam($this->sharedTeam->id)
            ->pluck('servers.id')
    )->toContain($this->deploymentServer->id);
});

test('shared team cannot deploy when deployment access is disabled', function () {
    $this->deploymentServer->sharedTeams()->attach(
        $this->sharedTeam->id,
        [
            'can_build' => false,
            'can_deploy' => false,
        ]
    );

    expect(
        Server::deployableByTeam($this->sharedTeam->id)
            ->pluck('servers.id')
    )->not->toContain($this->deploymentServer->id);
});

test('build-only access does not grant deployment access', function () {
    $this->deploymentServer->sharedTeams()->attach(
        $this->sharedTeam->id,
        [
            'can_build' => true,
            'can_deploy' => false,
        ]
    );

    expect(
        Server::deployableByTeam($this->sharedTeam->id)
            ->pluck('servers.id')
    )->not->toContain($this->deploymentServer->id);
});

test('dedicated build server cannot be used as a deployment destination', function () {
    $this->deploymentServer->settings()->update([
        'is_build_server' => true,
    ]);

    $this->deploymentServer->sharedTeams()->attach(
        $this->sharedTeam->id,
        [
            'can_build' => true,
            'can_deploy' => true,
        ]
    );

    expect(
        Server::deployableByTeam($this->sharedTeam->id)
            ->pluck('servers.id')
    )->not->toContain($this->deploymentServer->id);
});

test('unrelated team cannot deploy to another teams server', function () {
    expect(
        Server::deployableByTeam($this->unrelatedTeam->id)
            ->pluck('servers.id')
    )->not->toContain($this->deploymentServer->id);
});

test('unreachable shared deployment server is excluded from usable servers', function () {
    $this->deploymentServer->sharedTeams()->attach(
        $this->sharedTeam->id,
        [
            'can_build' => false,
            'can_deploy' => true,
        ]
    );

    $this->deploymentServer->settings()->update([
        'is_reachable' => false,
    ]);

    expect(
        Server::usableDeploymentServersForTeam($this->sharedTeam->id)
            ->pluck('servers.id')
    )->not->toContain($this->deploymentServer->id);
});

test('shared team can resolve an authorized destination', function () {
    $this->deploymentServer->sharedTeams()->attach(
        $this->sharedTeam->id,
        [
            'can_build' => false,
            'can_deploy' => true,
        ]
    );

    expect(
        StandaloneDocker::deployableByTeam($this->sharedTeam->id)
            ->whereKey($this->destination->id)
            ->exists()
    )->toBeTrue();
});

test('shared deployment server remains hidden and cannot be administered', function () {
    $this->deploymentServer->sharedTeams()->attach(
        $this->sharedTeam->id,
        [
            'can_build' => false,
            'can_deploy' => true,
        ]
    );

    $this->actingAs($this->sharedUser);
    session(['currentTeam' => $this->sharedTeam]);

    expect(Server::ownedByCurrentTeam()->whereKey($this->deploymentServer->id)->exists())
        ->toBeFalse()
        ->and($this->sharedUser->can('view', $this->deploymentServer))
        ->toBeFalse()
        ->and($this->sharedUser->can('update', $this->deploymentServer))
        ->toBeFalse()
        ->and($this->sharedUser->can('delete', $this->deploymentServer))
        ->toBeFalse()
        ->and($this->sharedUser->can('manageProxy', $this->deploymentServer))
        ->toBeFalse()
        ->and($this->sharedUser->can('viewSecurity', $this->deploymentServer))
        ->toBeFalse();
});
