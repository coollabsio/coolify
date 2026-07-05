<?php

use App\Models\SwarmDocker;
use App\Models\User;
use App\Policies\SwarmDockerPolicy;

it('allows any user to view any swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new SwarmDockerPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their team swarm docker', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->view($user, $swarmDocker))->toBeTrue();
});

it('denies user from viewing another team swarm docker', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 2]);

    $policy = new SwarmDockerPolicy;
    expect($policy->view($user, $swarmDocker))->toBeFalse();
});

it('allows admin to create swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new SwarmDockerPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin from creating swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new SwarmDockerPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->update($user, $swarmDocker))->toBeTrue();
});

it('denies non-admin from updating swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->update($user, $swarmDocker))->toBeFalse();
});

it('allows team admin to delete swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->delete($user, $swarmDocker))->toBeTrue();
});

it('denies non-admin from deleting swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->delete($user, $swarmDocker))->toBeFalse();
});

it('denies restore for swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->restore($user, $swarmDocker))->toBeFalse();
});

it('denies force delete for swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->forceDelete($user, $swarmDocker))->toBeFalse();
});
