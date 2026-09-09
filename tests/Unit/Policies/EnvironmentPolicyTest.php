<?php

use App\Models\Environment;
use App\Models\User;
use App\Policies\EnvironmentPolicy;

it('allows any user to view any environments', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new EnvironmentPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team environment', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn((object) ['team_id' => 1]);

    $policy = new EnvironmentPolicy;
    expect($policy->view($user, $environment))->toBeTrue();
});

it('denies non-member to view another team environment', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn((object) ['team_id' => 2]);

    $policy = new EnvironmentPolicy;
    expect($policy->view($user, $environment))->toBeFalse();
});

it('denies view when environment has no project', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn(null);

    $policy = new EnvironmentPolicy;
    expect($policy->view($user, $environment))->toBeFalse();
});

it('allows admin to create an environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new EnvironmentPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create an environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new EnvironmentPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn((object) ['team_id' => 1]);

    $policy = new EnvironmentPolicy;
    expect($policy->update($user, $environment))->toBeTrue();
});

it('denies team member to update their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn((object) ['team_id' => 1]);

    $policy = new EnvironmentPolicy;
    expect($policy->update($user, $environment))->toBeFalse();
});

it('denies update when environment has no project', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn(null);

    $policy = new EnvironmentPolicy;
    expect($policy->update($user, $environment))->toBeFalse();
});

it('allows team admin to delete their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn((object) ['team_id' => 1]);

    $policy = new EnvironmentPolicy;
    expect($policy->delete($user, $environment))->toBeTrue();
});

it('denies team member to delete their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn((object) ['team_id' => 1]);

    $policy = new EnvironmentPolicy;
    expect($policy->delete($user, $environment))->toBeFalse();
});

it('denies delete when environment has no project', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn(null);

    $policy = new EnvironmentPolicy;
    expect($policy->delete($user, $environment))->toBeFalse();
});

it('denies restore for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $environment = Mockery::mock(Environment::class)->makePartial();

    $policy = new EnvironmentPolicy;
    expect($policy->restore($user, $environment))->toBeFalse();
});

it('denies force delete for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $environment = Mockery::mock(Environment::class)->makePartial();

    $policy = new EnvironmentPolicy;
    expect($policy->forceDelete($user, $environment))->toBeFalse();
});
