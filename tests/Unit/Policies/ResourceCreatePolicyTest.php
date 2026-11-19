<?php

use App\Enums\AccessLevel;
use App\Models\Team;
use App\Models\User;
use App\Policies\ResourceCreatePolicy;
use App\Services\Access\AccessResolver;
use Illuminate\Container\Container;

afterEach(function () {
    Container::getInstance()->forgetInstance(AccessResolver::class);
});

it('allows admin to create any resource', function () {
    $team = new Team;
    $team->id = 1;
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(true);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ResourceCreatePolicy;
    expect($policy->createAny($user))->toBeTrue();
});

it('denies member from creating any resource', function () {
    $team = new Team;
    $team->id = 1;
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('currentTeam')->andReturn($team);

    $resolver = Mockery::mock(AccessResolver::class);
    $resolver->shouldReceive('allowsTeamWide')->with($user, AccessLevel::Write, 1)->andReturn(false);
    Container::getInstance()->instance(AccessResolver::class, $resolver);

    $policy = new ResourceCreatePolicy;
    expect($policy->createAny($user))->toBeFalse();
});
