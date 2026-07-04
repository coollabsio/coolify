<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\User;
use App\Policies\EnvironmentVariablePolicy;
use Illuminate\Support\Collection;

it('allows any user to view any environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new EnvironmentVariablePolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->teams = new Collection([(object) ['id' => 1]]);

    $resource = Mockery::mock(Application::class)->makePartial();
    $resource->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn($resource);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->view($user, $envVar))->toBeTrue();
});

it('denies non-team member from viewing environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->teams = new Collection([(object) ['id' => 2]]);

    $resource = Mockery::mock(Application::class)->makePartial();
    $resource->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn($resource);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->view($user, $envVar))->toBeFalse();
});

it('denies view when resourceable is null', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->teams = new Collection([(object) ['id' => 1]]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn(null);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->view($user, $envVar))->toBeFalse();
});

it('allows admin to create environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies member from creating environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $resource = Mockery::mock(Application::class)->makePartial();
    $resource->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn($resource);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->update($user, $envVar))->toBeTrue();
});

it('denies non-admin from updating environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $resource = Mockery::mock(Application::class)->makePartial();
    $resource->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn($resource);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->update($user, $envVar))->toBeFalse();
});

it('denies update when resourceable is null', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn(null);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->update($user, $envVar))->toBeFalse();
});

it('allows team admin to delete environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $resource = Mockery::mock(Application::class)->makePartial();
    $resource->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn($resource);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->delete($user, $envVar))->toBeTrue();
});

it('denies non-admin from deleting environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $resource = Mockery::mock(Application::class)->makePartial();
    $resource->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn($resource);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->delete($user, $envVar))->toBeFalse();
});

it('denies delete when resourceable is null', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn(null);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->delete($user, $envVar))->toBeFalse();
});

it('denies restore for environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();

    $policy = new EnvironmentVariablePolicy;
    expect($policy->restore($user, $envVar))->toBeFalse();
});

it('denies force delete for environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();

    $policy = new EnvironmentVariablePolicy;
    expect($policy->forceDelete($user, $envVar))->toBeFalse();
});

it('allows team admin to manage environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $resource = Mockery::mock(Application::class)->makePartial();
    $resource->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn($resource);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->manageEnvironment($user, $envVar))->toBeTrue();
});

it('denies non-admin from managing environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $resource = Mockery::mock(Application::class)->makePartial();
    $resource->shouldReceive('team')->andReturn((object) ['id' => 1]);

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn($resource);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->manageEnvironment($user, $envVar))->toBeFalse();
});

it('denies manage environment when resourceable is null', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $envVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $envVar->shouldReceive('getAttribute')->with('resourceable')->andReturn(null);

    $policy = new EnvironmentVariablePolicy;
    expect($policy->manageEnvironment($user, $envVar))->toBeFalse();
});
