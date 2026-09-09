<?php

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

it('allows any user to view any projects', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ProjectPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team project', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $policy = new ProjectPolicy;
    expect($policy->view($user, $project))->toBeTrue();
});

it('denies non-member to view another team project', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 2;

    $policy = new ProjectPolicy;
    expect($policy->view($user, $project))->toBeFalse();
});

it('allows admin to create a project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ProjectPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create a project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ProjectPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $policy = new ProjectPolicy;
    expect($policy->update($user, $project))->toBeTrue();
});

it('denies team member to update their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $policy = new ProjectPolicy;
    expect($policy->update($user, $project))->toBeFalse();
});

it('allows team admin to delete their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $policy = new ProjectPolicy;
    expect($policy->delete($user, $project))->toBeTrue();
});

it('denies team member to delete their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $policy = new ProjectPolicy;
    expect($policy->delete($user, $project))->toBeFalse();
});

it('denies restore for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $policy = new ProjectPolicy;
    expect($policy->restore($user, $project))->toBeFalse();
});

it('denies force delete for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $policy = new ProjectPolicy;
    expect($policy->forceDelete($user, $project))->toBeFalse();
});
