<?php

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ownerTeam = Team::factory()->create();
    $this->sharedTeam = Team::factory()->create();
    $this->unrelatedTeam = Team::factory()->create();

    $this->sharedUser = User::factory()->create();
    $this->sharedUser->teams()->attach($this->sharedTeam, ['role' => 'owner']);

    $this->buildServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $this->buildServer->settings()->update([
        'is_build_server' => true,
        'is_reachable' => true,
    ]);
});

test('owner team can use its own build server', function () {
    expect(Server::buildServers($this->ownerTeam->id)->pluck('servers.id'))
        ->toContain($this->buildServer->id);
});

test('shared team can use a shared build server when build access is enabled', function () {
    $this->buildServer->sharedTeams()->attach($this->sharedTeam->id, [
        'can_build' => true,
    ]);

    expect(Server::buildServers($this->sharedTeam->id)->pluck('servers.id'))
        ->toContain($this->buildServer->id);
});

test('shared team cannot use a shared build server when build access is disabled', function () {
    $this->buildServer->sharedTeams()->attach($this->sharedTeam->id, [
        'can_build' => false,
    ]);

    expect(Server::buildServers($this->sharedTeam->id)->pluck('servers.id'))
        ->not->toContain($this->buildServer->id);
});

test('unrelated team cannot use another teams build server', function () {
    expect(Server::buildServers($this->unrelatedTeam->id)->pluck('servers.id'))
        ->not->toContain($this->buildServer->id);
});

test('unreachable shared build server is not selected for new builds', function () {
    $this->buildServer->sharedTeams()->attach($this->sharedTeam->id, [
        'can_build' => true,
    ]);

    $this->buildServer->settings()->update([
        'is_reachable' => false,
    ]);

    expect(Server::buildServers($this->sharedTeam->id)->pluck('servers.id'))
        ->not->toContain($this->buildServer->id);
});

test('shared deployment server cannot be used as an external execution server', function () {
    $deploymentServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $deploymentServer->settings()->update([
        'is_build_server' => false,
        'is_reachable' => true,
    ]);

    $deploymentServer->sharedTeams()->attach($this->sharedTeam->id, [
        'can_build' => true,
    ]);

    expect(
        Server::accessibleDeploymentExecutionServersForTeam($this->sharedTeam->id)
            ->pluck('servers.id')
    )->not->toContain($deploymentServer->id);
});

test('owner team deployment server remains an accessible execution server', function () {
    $deploymentServer = Server::factory()->create([
        'team_id' => $this->sharedTeam->id,
    ]);

    $deploymentServer->settings()->update([
        'is_build_server' => false,
    ]);

    expect(
        Server::accessibleDeploymentExecutionServersForTeam($this->sharedTeam->id)
            ->pluck('servers.id')
    )->toContain($deploymentServer->id);
});

test('shared build server remains hidden from owned server listings', function () {
    $this->buildServer->sharedTeams()->attach($this->sharedTeam->id, [
        'can_build' => true,
    ]);

    $this->actingAs($this->sharedUser);
    session(['currentTeam' => $this->sharedTeam]);

    expect(Server::ownedByCurrentTeam()->pluck('servers.id'))
        ->not->toContain($this->buildServer->id);
});

test('shared team cannot view or administer the build server', function () {
    $this->buildServer->sharedTeams()->attach($this->sharedTeam->id, [
        'can_build' => true,
    ]);

    expect($this->sharedUser->can('view', $this->buildServer))->toBeFalse()
        ->and($this->sharedUser->can('update', $this->buildServer))->toBeFalse()
        ->and($this->sharedUser->can('delete', $this->buildServer))->toBeFalse()
        ->and($this->sharedUser->can('manageProxy', $this->buildServer))->toBeFalse()
        ->and($this->sharedUser->can('viewSecurity', $this->buildServer))->toBeFalse();
});

test('deleting the server removes its team sharing records', function () {
    $this->buildServer->sharedTeams()->attach($this->sharedTeam->id, [
        'can_build' => true,
    ]);

    $serverId = $this->buildServer->id;

    $this->buildServer->forceDelete();

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $serverId,
        'team_id' => $this->sharedTeam->id,
    ]);
});

test('deleting the shared team does not delete the owner teams server', function () {
    $this->buildServer->sharedTeams()->attach($this->sharedTeam->id, [
        'can_build' => true,
    ]);

    $serverId = $this->buildServer->id;

    $this->sharedTeam->delete();

    expect(Server::find($serverId))->not->toBeNull();

    $this->assertDatabaseMissing('server_team', [
        'server_id' => $serverId,
        'team_id' => $this->sharedTeam->id,
    ]);
});
