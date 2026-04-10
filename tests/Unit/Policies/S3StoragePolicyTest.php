<?php

use App\Models\S3Storage;
use App\Models\User;
use App\Policies\S3StoragePolicy;

it('allows team member to view S3 storage from their team', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn(1);
    $storage->team_id = 1;

    $policy = new S3StoragePolicy;
    expect($policy->view($user, $storage))->toBeTrue();
});

it('denies team member to view S3 storage from another team', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'owner']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn(2);
    $storage->team_id = 2;

    $policy = new S3StoragePolicy;
    expect($policy->view($user, $storage))->toBeFalse();
});

it('allows team admin to update S3 storage from their team', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn(1);
    $storage->team_id = 1;

    $policy = new S3StoragePolicy;
    expect($policy->update($user, $storage))->toBeTrue();
});

it('denies team member to update S3 storage from another team', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn(2);
    $storage->team_id = 2;

    $policy = new S3StoragePolicy;
    expect($policy->update($user, $storage))->toBeFalse();
});

it('allows team member to delete S3 storage from their team', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn(1);
    $storage->team_id = 1;

    $policy = new S3StoragePolicy;
    expect($policy->delete($user, $storage))->toBeTrue();
});

it('denies team member to delete S3 storage from another team', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'owner']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn(2);
    $storage->team_id = 2;

    $policy = new S3StoragePolicy;
    expect($policy->delete($user, $storage))->toBeFalse();
});

it('allows admin to create S3 storage', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new S3StoragePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin to create S3 storage', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new S3StoragePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team member to validate connection of S3 storage from their team', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn(1);
    $storage->team_id = 1;

    $policy = new S3StoragePolicy;
    expect($policy->validateConnection($user, $storage))->toBeTrue();
});

it('denies team member to validate connection of S3 storage from another team', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn(2);
    $storage->team_id = 2;

    $policy = new S3StoragePolicy;
    expect($policy->validateConnection($user, $storage))->toBeFalse();
});
