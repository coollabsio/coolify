<?php

use App\Enums\AccessLevel;
use App\Models\Application;
use App\Models\Team;
use App\Models\User;
use App\Policies\ApplicationPolicy;
use App\Services\Access\AccessResolver;
use Illuminate\Container\Container;

afterEach(function () {
    Container::getInstance()->forgetInstance(AccessResolver::class);
});

it('allows any user to view any applications', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ApplicationPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $application)->andReturn(true);

    $policy = new ApplicationPolicy;
    expect($policy->view($user, $application))->toBeTrue();
});

it('denies non-member to view another team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $application)->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->view($user, $application))->toBeFalse();
});

it('denies view when application has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $application)->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->view($user, $application))->toBeFalse();
});

it('allows admin to create an application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(true);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ApplicationPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create an application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(false);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ApplicationPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $application)->andReturn(true);

    $policy = new ApplicationPolicy;
    expect($policy->update($user, $application)->allowed())->toBeTrue();
});

it('denies team member to update their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $application)->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->update($user, $application)->allowed())->toBeFalse();
});

it('denies update when application has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $application)->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->update($user, $application)->allowed())->toBeFalse();
});

it('allows team admin to delete their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $application)->andReturn(true);

    $policy = new ApplicationPolicy;
    expect($policy->delete($user, $application))->toBeTrue();
});

it('denies team member to delete their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $application)->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->delete($user, $application))->toBeFalse();
});

it('denies delete when application has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $application)->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->delete($user, $application))->toBeFalse();
});

it('allows team admin to deploy their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Deploy, $application)->andReturn(true);

    $policy = new ApplicationPolicy;
    expect($policy->deploy($user, $application))->toBeTrue();
});

it('denies team member to deploy their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Deploy, $application)->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->deploy($user, $application))->toBeFalse();
});

it('allows team admin to manage environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $application)->andReturn(true);

    $policy = new ApplicationPolicy;
    expect($policy->manageEnvironment($user, $application))->toBeTrue();
});

it('denies team member to manage environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $application = Mockery::mock(Application::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $application)->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->manageEnvironment($user, $application))->toBeFalse();
});

it('denies restore for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $application = Mockery::mock(Application::class)->makePartial();

    $policy = new ApplicationPolicy;
    expect($policy->restore($user, $application))->toBeFalse();
});

it('denies force delete for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $application = Mockery::mock(Application::class)->makePartial();

    $policy = new ApplicationPolicy;
    expect($policy->forceDelete($user, $application))->toBeFalse();
});
