<?php

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
