<?php

use App\Models\ServiceDatabase;
use App\Models\User;
use App\Policies\ServiceDatabasePolicy;

it('allows admin to create service database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ServiceDatabasePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member from creating service database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ServiceDatabasePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('denies restore for service database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $serviceDb = Mockery::mock(ServiceDatabase::class)->makePartial();

    $policy = new ServiceDatabasePolicy;
    expect($policy->restore($user, $serviceDb))->toBeFalse();
});

it('denies force delete for service database', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $serviceDb = Mockery::mock(ServiceDatabase::class)->makePartial();

    $policy = new ServiceDatabasePolicy;
    expect($policy->forceDelete($user, $serviceDb))->toBeFalse();
});
