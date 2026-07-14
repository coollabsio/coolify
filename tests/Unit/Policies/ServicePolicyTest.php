<?php

use App\Models\Service;
use App\Models\User;
use App\Policies\ServicePolicy;

it('allows any user to view any services', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ServicePolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team service', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->view($user, $service))->toBeTrue();
});

it('denies non-member to view another team service', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 2]);

    $policy = new ServicePolicy;
    expect($policy->view($user, $service))->toBeFalse();
});

it('denies view when service has no team', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn(null);

    $policy = new ServicePolicy;
    expect($policy->view($user, $service))->toBeFalse();
});

it('allows admin to create a service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ServicePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create a service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ServicePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->update($user, $service))->toBeTrue();
});

it('denies team member to update their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->update($user, $service))->toBeFalse();
});

it('allows team admin to delete their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->delete($user, $service))->toBeTrue();
});

it('denies team member to delete their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->delete($user, $service))->toBeFalse();
});

it('denies delete when service has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn(null);

    $policy = new ServicePolicy;
    expect($policy->delete($user, $service))->toBeFalse();
});

it('allows team admin to deploy their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->deploy($user, $service))->toBeTrue();
});

it('denies team member to deploy their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->deploy($user, $service))->toBeFalse();
});

it('allows team admin to stop their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->stop($user, $service))->toBeTrue();
});

it('denies team member to stop their own team service', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->stop($user, $service))->toBeFalse();
});

it('allows team admin to manage service environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->manageEnvironment($user, $service))->toBeTrue();
});

it('denies team member to manage service environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->manageEnvironment($user, $service))->toBeFalse();
});

it('allows team admin to access terminal', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->accessTerminal($user, $service))->toBeTrue();
});

it('denies team member to access terminal', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ServicePolicy;
    expect($policy->accessTerminal($user, $service))->toBeFalse();
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
