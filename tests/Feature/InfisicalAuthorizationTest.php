<?php

use App\Models\InfisicalConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- InfisicalConnection ---

test('an owner may view and manage connections', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner->id, ['role' => 'owner']);
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);

    session(['currentTeam' => ['id' => $team->id]]);

    expect($owner->can('viewAny', InfisicalConnection::class))->toBeTrue();
    expect($owner->can('create', InfisicalConnection::class))->toBeTrue();
    expect($owner->can('view', $connection))->toBeTrue();
    expect($owner->can('update', $connection))->toBeTrue();
    expect($owner->can('delete', $connection))->toBeTrue();
});

test('an admin may view and manage connections', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin']);
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);

    expect($admin->can('view', $connection))->toBeTrue();
    expect($admin->can('update', $connection))->toBeTrue();
    expect($admin->can('delete', $connection))->toBeTrue();
});

test('a member may not view or manage connections', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);

    session(['currentTeam' => ['id' => $team->id]]);

    expect($member->can('viewAny', InfisicalConnection::class))->toBeFalse();
    expect($member->can('create', InfisicalConnection::class))->toBeFalse();
    expect($member->can('view', $connection))->toBeFalse();
    expect($member->can('update', $connection))->toBeFalse();
    expect($member->can('delete', $connection))->toBeFalse();
});

test('an owner of another team may not touch this team connection', function () {
    $connection = InfisicalConnection::factory()->create();

    $otherTeam = Team::factory()->create();
    $outsider = User::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'owner']);

    expect($outsider->can('view', $connection))->toBeFalse();
    expect($outsider->can('update', $connection))->toBeFalse();
    expect($outsider->can('delete', $connection))->toBeFalse();
});

test('a user with no team membership at all may not touch any connection', function () {
    $connection = InfisicalConnection::factory()->create();
    $unaffiliated = User::factory()->create();

    expect($unaffiliated->can('view', $connection))->toBeFalse();
    expect($unaffiliated->can('update', $connection))->toBeFalse();
    expect($unaffiliated->can('delete', $connection))->toBeFalse();
});

test('an admin in one team may not touch another team connection where they are only a member', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $user = User::factory()->create();
    $teamA->members()->attach($user->id, ['role' => 'admin']);
    $teamB->members()->attach($user->id, ['role' => 'member']);

    $connection = InfisicalConnection::factory()->create(['team_id' => $teamB->id]);

    session(['currentTeam' => ['id' => $teamA->id]]);

    expect($user->can('view', $connection))->toBeFalse();
    expect($user->can('update', $connection))->toBeFalse();
    expect($user->can('delete', $connection))->toBeFalse();
});
