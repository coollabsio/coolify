<?php

use App\Models\Application;
use App\Models\User;
use App\Policies\ResourceCreatePolicy;

it('allows admin to create any resource', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ResourceCreatePolicy;
    expect($policy->createAny($user))->toBeTrue();
});

it('denies member from creating any resource', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ResourceCreatePolicy;
    expect($policy->createAny($user))->toBeFalse();
});

it('allows admin to create a valid resource class', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ResourceCreatePolicy;
    expect($policy->create($user, Application::class))->toBeTrue();
});

it('denies member from creating a valid resource class', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ResourceCreatePolicy;
    expect($policy->create($user, Application::class))->toBeFalse();
});

it('denies admin from creating an invalid resource class', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ResourceCreatePolicy;
    expect($policy->create($user, 'App\Models\NonExistent'))->toBeFalse();
});

it('allows admin to authorize all resource creation', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ResourceCreatePolicy;
    expect($policy->authorizeAllResourceCreation($user))->toBeTrue();
});

it('denies member from authorizing all resource creation', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ResourceCreatePolicy;
    expect($policy->authorizeAllResourceCreation($user))->toBeFalse();
});
