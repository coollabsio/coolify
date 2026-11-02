<?php

use App\Enums\AccessLevel;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Policies\EnvironmentPolicy;
use App\Services\Access\AccessResolver;
use Illuminate\Container\Container;

afterEach(function () {
    Container::getInstance()->forgetInstance(AccessResolver::class);
});

it('allows any user to view any environments', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new EnvironmentPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $environment = Mockery::mock(Environment::class)->makePartial();

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('canOpen')->with($user, $environment)->andReturn(true);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new EnvironmentPolicy;
    expect($policy->view($user, $environment))->toBeTrue();
});

it('denies non-member to view another team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $environment = Mockery::mock(Environment::class)->makePartial();

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('canOpen')->with($user, $environment)->andReturn(false);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new EnvironmentPolicy;
    expect($policy->view($user, $environment))->toBeFalse();
});

it('denies view when environment has no project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $environment)->andReturn(false);

    $policy = new EnvironmentPolicy;
    expect($policy->view($user, $environment))->toBeFalse();
});

it('allows admin to create an environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(true);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new EnvironmentPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create an environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(false);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new EnvironmentPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $environment = Mockery::mock(Environment::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $environment)->andReturn(true);

    $policy = new EnvironmentPolicy;
    expect($policy->update($user, $environment))->toBeTrue();
});

it('denies team member to update their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $environment = Mockery::mock(Environment::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $environment)->andReturn(false);

    $policy = new EnvironmentPolicy;
    expect($policy->update($user, $environment))->toBeFalse();
});

it('denies update when environment has no project', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $environment)->andReturn(false);

    $policy = new EnvironmentPolicy;
    expect($policy->update($user, $environment))->toBeFalse();
});

it('allows team admin to delete their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $environment = Mockery::mock(Environment::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $environment)->andReturn(true);

    $policy = new EnvironmentPolicy;
    expect($policy->delete($user, $environment))->toBeTrue();
});

it('denies team member to delete their own team environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $environment = Mockery::mock(Environment::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $environment)->andReturn(false);

    $policy = new EnvironmentPolicy;
    expect($policy->delete($user, $environment))->toBeFalse();
});

it('denies delete when environment has no project', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->shouldReceive('getAttribute')->with('project')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $environment)->andReturn(false);

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
