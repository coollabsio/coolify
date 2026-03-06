<?php

use App\Models\User;
use App\Policies\GithubAppPolicy;

it('allows any user to view any github apps', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new GithubAppPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows any user to view system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = true;
    };

    $policy = new GithubAppPolicy;
    expect($policy->view($user, $model))->toBeTrue();
});

it('allows team member to view non-system-wide github app', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = false;
    };

    $policy = new GithubAppPolicy;
    expect($policy->view($user, $model))->toBeTrue();
});

it('denies non-team member to view non-system-wide github app', function () {
    $teams = collect([
        (object) ['id' => 2, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = false;
    };

    $policy = new GithubAppPolicy;
    expect($policy->view($user, $model))->toBeFalse();
});

it('allows admin to create github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new GithubAppPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin to create github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new GithubAppPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows user with system access to update system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(true);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = true;
    };

    $policy = new GithubAppPolicy;
    expect($policy->update($user, $model))->toBeTrue();
});

it('denies user without system access to update system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(false);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = true;
    };

    $policy = new GithubAppPolicy;
    expect($policy->update($user, $model))->toBeFalse();
});

it('allows team admin to update non-system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = false;
    };

    $policy = new GithubAppPolicy;
    expect($policy->update($user, $model))->toBeTrue();
});

it('denies team member to update non-system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = false;
    };

    $policy = new GithubAppPolicy;
    expect($policy->update($user, $model))->toBeFalse();
});

it('allows user with system access to delete system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(true);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = true;
    };

    $policy = new GithubAppPolicy;
    expect($policy->delete($user, $model))->toBeTrue();
});

it('denies user without system access to delete system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(false);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = true;
    };

    $policy = new GithubAppPolicy;
    expect($policy->delete($user, $model))->toBeFalse();
});

it('allows team admin to delete non-system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = false;
    };

    $policy = new GithubAppPolicy;
    expect($policy->delete($user, $model))->toBeTrue();
});

it('denies team member to delete non-system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = false;
    };

    $policy = new GithubAppPolicy;
    expect($policy->delete($user, $model))->toBeFalse();
});

it('denies restore of github app', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = false;
    };

    $policy = new GithubAppPolicy;
    expect($policy->restore($user, $model))->toBeFalse();
});

it('denies force delete of github app', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $model = new class
    {
        public $team_id = 1;

        public $is_system_wide = false;
    };

    $policy = new GithubAppPolicy;
    expect($policy->forceDelete($user, $model))->toBeFalse();
});
