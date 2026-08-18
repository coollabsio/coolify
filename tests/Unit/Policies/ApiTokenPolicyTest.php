<?php

use App\Models\User;
use App\Policies\ApiTokenPolicy;
use Laravel\Sanctum\PersonalAccessToken;

it('allows any user to view any api tokens', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ApiTokenPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows any user to create api tokens', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ApiTokenPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('allows any user to manage api tokens', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ApiTokenPolicy;
    expect($policy->manage($user))->toBeTrue();
});

it('allows owner to view their own api token', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->id = 1;

    $token = Mockery::mock(PersonalAccessToken::class)->makePartial();
    $token->tokenable_id = 1;
    $token->tokenable_type = User::class;

    $policy = new ApiTokenPolicy;
    expect($policy->view($user, $token))->toBeTrue();
});

it('denies non-owner from viewing api token', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->id = 2;

    $token = Mockery::mock(PersonalAccessToken::class)->makePartial();
    $token->tokenable_id = 1;
    $token->tokenable_type = User::class;

    $policy = new ApiTokenPolicy;
    expect($policy->view($user, $token))->toBeFalse();
});

it('allows owner to update their own api token', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->id = 1;

    $token = Mockery::mock(PersonalAccessToken::class)->makePartial();
    $token->tokenable_id = 1;
    $token->tokenable_type = User::class;

    $policy = new ApiTokenPolicy;
    expect($policy->update($user, $token))->toBeTrue();
});

it('denies non-owner from updating api token', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->id = 2;

    $token = Mockery::mock(PersonalAccessToken::class)->makePartial();
    $token->tokenable_id = 1;
    $token->tokenable_type = User::class;

    $policy = new ApiTokenPolicy;
    expect($policy->update($user, $token))->toBeFalse();
});

it('allows owner to delete their own api token', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->id = 1;

    $token = Mockery::mock(PersonalAccessToken::class)->makePartial();
    $token->tokenable_id = 1;
    $token->tokenable_type = User::class;

    $policy = new ApiTokenPolicy;
    expect($policy->delete($user, $token))->toBeTrue();
});

it('denies non-owner from deleting api token', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->id = 2;

    $token = Mockery::mock(PersonalAccessToken::class)->makePartial();
    $token->tokenable_id = 1;
    $token->tokenable_type = User::class;

    $policy = new ApiTokenPolicy;
    expect($policy->delete($user, $token))->toBeFalse();
});

it('allows admin to use root permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ApiTokenPolicy;
    expect($policy->useRootPermissions($user))->toBeTrue();
});

it('allows owner to use root permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);
    $user->shouldReceive('isOwner')->andReturn(true);

    $policy = new ApiTokenPolicy;
    expect($policy->useRootPermissions($user))->toBeTrue();
});

it('denies member from using root permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);
    $user->shouldReceive('isOwner')->andReturn(false);

    $policy = new ApiTokenPolicy;
    expect($policy->useRootPermissions($user))->toBeFalse();
});

it('allows admin to use write permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ApiTokenPolicy;
    expect($policy->useWritePermissions($user))->toBeTrue();
});

it('denies member from using write permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);
    $user->shouldReceive('isOwner')->andReturn(false);

    $policy = new ApiTokenPolicy;
    expect($policy->useWritePermissions($user))->toBeFalse();
});

it('allows admin to use deploy permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ApiTokenPolicy;
    expect($policy->useDeployPermissions($user))->toBeTrue();
});

it('allows owner to use deploy permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);
    $user->shouldReceive('isOwner')->andReturn(true);

    $policy = new ApiTokenPolicy;
    expect($policy->useDeployPermissions($user))->toBeTrue();
});

it('denies member from using deploy permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);
    $user->shouldReceive('isOwner')->andReturn(false);

    $policy = new ApiTokenPolicy;
    expect($policy->useDeployPermissions($user))->toBeFalse();
});

it('allows admin to use sensitive permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ApiTokenPolicy;
    expect($policy->useSensitivePermissions($user))->toBeTrue();
});

it('allows owner to use sensitive permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);
    $user->shouldReceive('isOwner')->andReturn(true);

    $policy = new ApiTokenPolicy;
    expect($policy->useSensitivePermissions($user))->toBeTrue();
});

it('denies member from using sensitive permissions', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);
    $user->shouldReceive('isOwner')->andReturn(false);

    $policy = new ApiTokenPolicy;
    expect($policy->useSensitivePermissions($user))->toBeFalse();
});
