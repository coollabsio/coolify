<?php

use App\Enums\AccessLevel;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Access\AccessResolver;
use Illuminate\Container\Container;

afterEach(function () {
    Container::getInstance()->forgetInstance(AccessResolver::class);
});

it('allows any user to view any projects', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ProjectPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('canOpen')->with($user, $project)->andReturn(true);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ProjectPolicy;
    expect($policy->view($user, $project))->toBeTrue();
});

it('denies non-member to view another team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 2;

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('canOpen')->with($user, $project)->andReturn(false);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ProjectPolicy;
    expect($policy->view($user, $project))->toBeFalse();
});

it('allows admin to create a project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(true);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ProjectPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create a project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(false);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ProjectPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $project)->andReturn(true);

    $policy = new ProjectPolicy;
    expect($policy->update($user, $project))->toBeTrue();
});

it('denies team member to update their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $project)->andReturn(false);

    $policy = new ProjectPolicy;
    expect($policy->update($user, $project))->toBeFalse();
});

it('allows team admin to delete their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $project)->andReturn(true);

    $policy = new ProjectPolicy;
    expect($policy->delete($user, $project))->toBeTrue();
});

it('denies team member to delete their own team project', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $project = Mockery::mock(Project::class)->makePartial();
    $project->team_id = 1;

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $project)->andReturn(false);

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
