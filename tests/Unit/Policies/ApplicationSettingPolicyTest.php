<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\User;
use App\Policies\ApplicationSettingPolicy;

it('allows any user to view any application settings', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ApplicationSettingPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view application setting', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationSettingPolicy;
    expect($policy->view($user, $setting))->toBeTrue();
});

it('denies non-team member from viewing application setting', function () {
    $teams = collect([
        (object) ['id' => 2, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationSettingPolicy;
    expect($policy->view($user, $setting))->toBeFalse();
});

it('denies viewing application setting with null application', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn(null);

    $policy = new ApplicationSettingPolicy;
    expect($policy->view($user, $setting))->toBeFalse();
});

it('allows admin user to create application setting', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ApplicationSettingPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin user from creating application setting', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ApplicationSettingPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update application setting', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationSettingPolicy;
    expect($policy->update($user, $setting))->toBeTrue();
});

it('denies team member from updating application setting', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationSettingPolicy;
    expect($policy->update($user, $setting))->toBeFalse();
});

it('denies updating application setting with null application', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn(null);

    $policy = new ApplicationSettingPolicy;
    expect($policy->update($user, $setting))->toBeFalse();
});

it('allows team admin to delete application setting', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationSettingPolicy;
    expect($policy->delete($user, $setting))->toBeTrue();
});

it('denies team member from deleting application setting', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationSettingPolicy;
    expect($policy->delete($user, $setting))->toBeFalse();
});

it('denies deleting application setting with null application', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();
    $setting->shouldReceive('getAttribute')->with('application')->andReturn(null);

    $policy = new ApplicationSettingPolicy;
    expect($policy->delete($user, $setting))->toBeFalse();
});

it('denies restoring application setting', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();

    $policy = new ApplicationSettingPolicy;
    expect($policy->restore($user, $setting))->toBeFalse();
});

it('denies force deleting application setting', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $setting = Mockery::mock(ApplicationSetting::class)->makePartial();

    $policy = new ApplicationSettingPolicy;
    expect($policy->forceDelete($user, $setting))->toBeFalse();
});
