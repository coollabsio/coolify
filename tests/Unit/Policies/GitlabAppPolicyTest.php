<?php

use App\Models\GitlabApp;
use App\Models\User;
use App\Policies\GitlabAppPolicy;

it('allows any user to view any gitlab apps', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new GitlabAppPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows any user to view system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $model = mockGitlabApp(teamId: 1, isSystemWide: true);

    $policy = new GitlabAppPolicy;
    expect($policy->view($user, $model))->toBeTrue();
});

it('allows team member to view non-system-wide gitlab app', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $model = mockGitlabApp(teamId: 1, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->view($user, $model))->toBeTrue();
});

it('denies non-team member to view non-system-wide gitlab app', function () {
    $teams = collect([
        (object) ['id' => 2, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $model = mockGitlabApp(teamId: 1, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->view($user, $model))->toBeFalse();
});

it('allows admin to create gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new GitlabAppPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin to create gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new GitlabAppPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows user with system access to update system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(true);

    $model = mockGitlabApp(teamId: 1, isSystemWide: true);

    $policy = new GitlabAppPolicy;
    expect($policy->update($user, $model))->toBeTrue();
});

it('denies user without system access to update system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(false);

    $model = mockGitlabApp(teamId: 1, isSystemWide: true);

    $policy = new GitlabAppPolicy;
    expect($policy->update($user, $model))->toBeFalse();
});

it('allows team admin to update non-system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $model = mockGitlabApp(teamId: 1, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->update($user, $model))->toBeTrue();
});

it('denies team member to update non-system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $model = mockGitlabApp(teamId: 1, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->update($user, $model))->toBeFalse();
});

it('allows user with system access to delete system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(true);

    $model = mockGitlabApp(teamId: 1, isSystemWide: true);

    $policy = new GitlabAppPolicy;
    expect($policy->delete($user, $model))->toBeTrue();
});

it('denies user without system access to delete system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(false);

    $model = mockGitlabApp(teamId: 1, isSystemWide: true);

    $policy = new GitlabAppPolicy;
    expect($policy->delete($user, $model))->toBeFalse();
});

it('allows team admin to delete non-system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $model = mockGitlabApp(teamId: 1, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->delete($user, $model))->toBeTrue();
});

it('denies team member to delete non-system-wide gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $model = mockGitlabApp(teamId: 1, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->delete($user, $model))->toBeFalse();
});

it('denies update when team_id is null without type error', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldNotReceive('isAdminOfTeam');

    $model = mockGitlabApp(teamId: null, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->update($user, $model))->toBeFalse();
});

it('denies delete when team_id is null without type error', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldNotReceive('isAdminOfTeam');

    $model = mockGitlabApp(teamId: null, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->delete($user, $model))->toBeFalse();
});

it('denies restore of gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $model = mockGitlabApp(teamId: 1, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->restore($user, $model))->toBeFalse();
});

it('denies force delete of gitlab app', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $model = mockGitlabApp(teamId: 1, isSystemWide: false);

    $policy = new GitlabAppPolicy;
    expect($policy->forceDelete($user, $model))->toBeFalse();
});

function mockGitlabApp(?int $teamId, bool $isSystemWide): GitlabApp
{
    $gitlabApp = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabApp->team_id = $teamId;
    $gitlabApp->is_system_wide = $isSystemWide;

    return $gitlabApp;
}
