<?php

use App\Enums\AccessLevel;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Policies\ServicePolicy;
use App\Services\Access\AccessResolver;
use Illuminate\Container\Container;

afterEach(function () {
    Container::getInstance()->forgetInstance(AccessResolver::class);
});

it('allows any user to view any services', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ServicePolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $service)->andReturn(true);

    $policy = new ServicePolicy;
    expect($policy->view($user, $service))->toBeTrue();
});

it('denies non-member to view another team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $service)->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->view($user, $service))->toBeFalse();
});

it('denies view when service has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $service)->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->view($user, $service))->toBeFalse();
});

it('allows admin to create a service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(true);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ServicePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create a service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(false);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ServicePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $service)->andReturn(true);

    $policy = new ServicePolicy;
    expect($policy->update($user, $service))->toBeTrue();
});

it('denies team member to update their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $service)->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->update($user, $service))->toBeFalse();
});

it('allows team admin to delete their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $service)->andReturn(true);

    $policy = new ServicePolicy;
    expect($policy->delete($user, $service))->toBeTrue();
});

it('denies team member to delete their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $service)->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->delete($user, $service))->toBeFalse();
});

it('denies delete when service has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $service)->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->delete($user, $service))->toBeFalse();
});

it('allows team admin to deploy their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Deploy, $service)->andReturn(true);

    $policy = new ServicePolicy;
    expect($policy->deploy($user, $service))->toBeTrue();
});

it('denies team member to deploy their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Deploy, $service)->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->deploy($user, $service))->toBeFalse();
});

it('allows team admin to stop their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Deploy, $service)->andReturn(true);

    $policy = new ServicePolicy;
    expect($policy->stop($user, $service))->toBeTrue();
});

it('denies team member to stop their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Deploy, $service)->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->stop($user, $service))->toBeFalse();
});

it('allows team admin to manage service environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $service)->andReturn(true);

    $policy = new ServicePolicy;
    expect($policy->manageEnvironment($user, $service))->toBeTrue();
});

it('denies team member to manage service environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $service = Mockery::mock(Service::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $service)->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->manageEnvironment($user, $service))->toBeFalse();
});

it('denies restore for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $service = Mockery::mock(Service::class)->makePartial();

    $policy = new ServicePolicy;
    expect($policy->restore($user, $service))->toBeFalse();
});

it('denies force delete for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $service = Mockery::mock(Service::class)->makePartial();

    $policy = new ServicePolicy;
    expect($policy->forceDelete($user, $service))->toBeFalse();
});
