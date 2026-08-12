<?php

use App\Models\StandaloneDocker;
use App\Models\User;
use App\Policies\StandaloneDockerPolicy;

it('allows any user to view any standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new StandaloneDockerPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their team standalone docker', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->view($user, $standaloneDocker))->toBeTrue();
});

it('denies user from viewing another team standalone docker', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 2]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->view($user, $standaloneDocker))->toBeFalse();
});

it('allows admin to create standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new StandaloneDockerPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin from creating standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new StandaloneDockerPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->update($user, $standaloneDocker))->toBeTrue();
});

it('denies non-admin from updating standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->update($user, $standaloneDocker))->toBeFalse();
});

it('allows team admin to delete standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->delete($user, $standaloneDocker))->toBeTrue();
});

it('denies non-admin from deleting standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->delete($user, $standaloneDocker))->toBeFalse();
});

it('denies restore for standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->restore($user, $standaloneDocker))->toBeFalse();
});

it('denies force delete for standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->forceDelete($user, $standaloneDocker))->toBeFalse();
});
