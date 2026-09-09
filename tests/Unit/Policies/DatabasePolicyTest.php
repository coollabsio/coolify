<?php

use App\Models\StandalonePostgresql;
use App\Models\User;
use App\Policies\DatabasePolicy;

it('allows any user to view any databases', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new DatabasePolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their own team database', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->view($user, $database))->toBeTrue();
});

it('denies non-member to view another team database', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 2]);

    $policy = new DatabasePolicy;
    expect($policy->view($user, $database))->toBeFalse();
});

it('denies view when database has no team', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn(null);

    $policy = new DatabasePolicy;
    expect($policy->view($user, $database))->toBeFalse();
});

it('allows admin to create a database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new DatabasePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member to create a database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new DatabasePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->update($user, $database)->allowed())->toBeTrue();
});

it('denies team member to update their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->update($user, $database)->allowed())->toBeFalse();
});

it('denies update when database has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn(null);

    $policy = new DatabasePolicy;
    expect($policy->update($user, $database)->allowed())->toBeFalse();
});

it('allows team admin to delete their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->delete($user, $database))->toBeTrue();
});

it('denies team member to delete their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->delete($user, $database))->toBeFalse();
});

it('denies delete when database has no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn(null);

    $policy = new DatabasePolicy;
    expect($policy->delete($user, $database))->toBeFalse();
});

it('allows team admin to manage their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->manage($user, $database))->toBeTrue();
});

it('denies team member to manage their own team database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->manage($user, $database))->toBeFalse();
});

it('allows team admin to manage backups', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->manageBackups($user, $database))->toBeTrue();
});

it('denies team member to manage backups', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->manageBackups($user, $database))->toBeFalse();
});

it('allows team admin to manage database environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $policy = new DatabasePolicy;
    expect($policy->manageEnvironment($user, $database))->toBeTrue();
});

it('denies team member to manage database environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('team')->andReturn((object) ['id' => 1]);

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
