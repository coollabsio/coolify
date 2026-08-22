<?php

use App\Models\ServiceApplication;
use App\Models\User;
use App\Policies\ServiceApplicationPolicy;

it('allows admin to create service application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ServiceApplicationPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member from creating service application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ServiceApplicationPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('denies restore for service application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $serviceApp = Mockery::mock(ServiceApplication::class)->makePartial();

    $policy = new ServiceApplicationPolicy;
    expect($policy->restore($user, $serviceApp))->toBeFalse();
});

it('denies force delete for service application', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $serviceApp = Mockery::mock(ServiceApplication::class)->makePartial();

    $policy = new ServiceApplicationPolicy;
    expect($policy->forceDelete($user, $serviceApp))->toBeFalse();
});
