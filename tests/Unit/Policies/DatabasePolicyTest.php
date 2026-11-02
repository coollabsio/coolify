<?php

use App\Enums\AccessLevel;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Policies\DatabasePolicy;
use App\Services\Access\AccessResolver;
use Illuminate\Container\Container;

afterEach(function () {
    Container::getInstance()->forgetInstance(AccessResolver::class);
});

it('allows any user to view any databases', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new DatabasePolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $database)->andReturn(true);

    $policy = new DatabasePolicy;
    expect($policy->view($user, $database))->toBeTrue();
});

it('denies non-member to view another team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->view($user, $database))->toBeFalse();
});

it('denies view when database has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Read, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->view($user, $database))->toBeFalse();
});

it('allows admin to create a database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(true);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new DatabasePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create a database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $team = new Team;
    $team->id = 1;
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(false);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new DatabasePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(true);

    $policy = new DatabasePolicy;
    expect($policy->update($user, $database)->allowed())->toBeTrue();
});

it('denies team member to update their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->update($user, $database)->allowed())->toBeFalse();
});

it('denies update when database has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->update($user, $database)->allowed())->toBeFalse();
});

it('allows team admin to delete their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $database)->andReturn(true);

    $policy = new DatabasePolicy;
    expect($policy->delete($user, $database))->toBeTrue();
});

it('denies team member to delete their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->delete($user, $database))->toBeFalse();
});

it('denies delete when database has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn(null);

    $user->shouldReceive('canAccess')->with(AccessLevel::Delete, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->delete($user, $database))->toBeFalse();
});

it('allows team admin to manage their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(true);

    $policy = new DatabasePolicy;
    expect($policy->manage($user, $database))->toBeTrue();
});

it('denies team member to manage their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->manage($user, $database))->toBeFalse();
});

it('allows team admin to manage backups', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(true);

    $policy = new DatabasePolicy;
    expect($policy->manageBackups($user, $database))->toBeTrue();
});

it('denies team member to manage backups', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->manageBackups($user, $database))->toBeFalse();
});

it('allows team admin to manage database environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(true);

    $policy = new DatabasePolicy;
    expect($policy->manageEnvironment($user, $database))->toBeTrue();
});

it('denies team member to manage database environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $user->shouldReceive('canAccess')->with(AccessLevel::Write, $database)->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->manageEnvironment($user, $database))->toBeFalse();
});

it('denies restore for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $policy = new DatabasePolicy;
    expect($policy->restore($user, $database))->toBeFalse();
});

it('denies force delete for any user', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $policy = new DatabasePolicy;
    expect($policy->forceDelete($user, $database))->toBeFalse();
});
