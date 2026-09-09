<?php

use App\Models\Application;
use App\Models\User;
use App\Policies\ApplicationPolicy;

it('allows any user to view any applications', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ApplicationPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team application', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->view($user, $application))->toBeTrue();
});

it('denies non-member to view another team application', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 2]);

    $policy = new ApplicationPolicy;
    expect($policy->view($user, $application))->toBeFalse();
});

it('denies view when application has no team', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn(null);

    $policy = new ApplicationPolicy;
    expect($policy->view($user, $application))->toBeFalse();
});

it('allows admin to create an application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ApplicationPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create an application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->update($user, $application)->allowed())->toBeTrue();
});

it('denies team member to update their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->update($user, $application)->allowed())->toBeFalse();
});

it('denies update when application has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn(null);

    $policy = new ApplicationPolicy;
    expect($policy->update($user, $application)->allowed())->toBeFalse();
});

it('allows team admin to delete their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->delete($user, $application))->toBeTrue();
});

it('denies team member to delete their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->delete($user, $application))->toBeFalse();
});

it('denies delete when application has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn(null);

    $policy = new ApplicationPolicy;
    expect($policy->delete($user, $application))->toBeFalse();
});

it('allows team admin to deploy their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->deploy($user, $application))->toBeTrue();
});

it('denies team member to deploy their own team application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->deploy($user, $application))->toBeFalse();
});

it('allows team admin to manage deployments', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->manageDeployments($user, $application))->toBeTrue();
});

it('denies team member to manage deployments', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->manageDeployments($user, $application))->toBeFalse();
});

it('allows team admin to manage environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->manageEnvironment($user, $application))->toBeTrue();
});

it('denies team member to manage environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new ApplicationPolicy;
    expect($policy->manageEnvironment($user, $application))->toBeFalse();
});

it('allows admin to cleanup deployment queue', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ApplicationPolicy;
    expect($policy->cleanupDeploymentQueue($user))->toBeTrue();
});

it('denies member to cleanup deployment queue', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ApplicationPolicy;
    expect($policy->cleanupDeploymentQueue($user))->toBeFalse();
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
