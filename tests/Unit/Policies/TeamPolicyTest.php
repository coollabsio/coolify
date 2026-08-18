<?php

use App\Models\Team;
use App\Models\User;
use App\Policies\TeamPolicy;

function teamPolicyUserWithTeams(array $teamIds): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect(
        array_map(fn (int $teamId): object => (object) ['id' => $teamId], $teamIds)
    ));

    return $user;
}

function teamPolicyTeam(int $teamId): Team
{
    $team = Mockery::mock(Team::class)->makePartial();
    $team->shouldReceive('getAttribute')->with('id')->andReturn($teamId);

    return $team;
}

it('allows any authenticated user to view any teams list', function () {
    $user = Mockery::mock(User::class)->makePartial();

    expect((new TeamPolicy)->viewAny($user))->toBeTrue();
});

it('allows authenticated users to create teams', function () {
    $user = Mockery::mock(User::class)->makePartial();

    expect((new TeamPolicy)->create($user))->toBeTrue();
});

it('allows target team members to view the team', function () {
    $user = teamPolicyUserWithTeams([1]);
    $team = teamPolicyTeam(1);

    expect((new TeamPolicy)->view($user, $team))->toBeTrue();
});

it('denies non-members from viewing the team', function () {
    $user = teamPolicyUserWithTeams([2]);
    $team = teamPolicyTeam(1);

    expect((new TeamPolicy)->view($user, $team))->toBeFalse();
});

it('allows target team admins to perform privileged team actions', function (string $ability) {
    $user = teamPolicyUserWithTeams([1]);
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $team = teamPolicyTeam(1);

    expect((new TeamPolicy)->{$ability}($user, $team))->toBeTrue();
})->with([
    'update',
    'delete',
    'manageMembers',
    'viewAdmin',
    'manageInvitations',
]);

it('denies target team members even when their current session role is admin elsewhere', function (string $ability) {
    $user = teamPolicyUserWithTeams([1, 2]);
    $user->shouldReceive('isAdmin')->andReturn(true);
    $user->shouldReceive('isOwner')->andReturn(false);
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);
    $team = teamPolicyTeam(1);

    expect((new TeamPolicy)->{$ability}($user, $team))->toBeFalse();
})->with([
    'update',
    'delete',
    'manageMembers',
    'viewAdmin',
    'manageInvitations',
]);

it('denies non-members from privileged team actions', function (string $ability) {
    $user = teamPolicyUserWithTeams([2]);
    $team = teamPolicyTeam(1);

    expect((new TeamPolicy)->{$ability}($user, $team))->toBeFalse();
})->with([
    'update',
    'delete',
    'manageMembers',
    'viewAdmin',
    'manageInvitations',
]);
