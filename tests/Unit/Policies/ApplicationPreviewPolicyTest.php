<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\User;
use App\Policies\ApplicationPreviewPolicy;

it('allows any user to view any application previews', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new ApplicationPreviewPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view application preview', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->view($user, $preview))->toBeTrue();
});

it('denies non-team member from viewing application preview', function () {
    $teams = collect([
        (object) ['id' => 2, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->view($user, $preview))->toBeFalse();
});

it('denies viewing application preview with null application', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn(null);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->view($user, $preview))->toBeFalse();
});

it('allows admin user to create application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin user from creating application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->update($user, $preview)->allowed())->toBeTrue();
});

it('denies team member from updating application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->update($user, $preview)->allowed())->toBeFalse();
});

it('denies updating application preview with null application', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn(null);

    $policy = new ApplicationPreviewPolicy;
    $response = $policy->update($user, $preview);
    expect($response->allowed())->toBeFalse();
});

it('allows team admin to delete application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->delete($user, $preview))->toBeTrue();
});

it('denies team member from deleting application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->delete($user, $preview))->toBeFalse();
});

it('denies deleting application preview with null application', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn(null);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->delete($user, $preview))->toBeFalse();
});

it('allows team admin to deploy application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->deploy($user, $preview))->toBeTrue();
});

it('denies team member from deploying application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->deploy($user, $preview))->toBeFalse();
});

it('allows team admin to manage preview deployments', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->manageDeployments($user, $preview))->toBeTrue();
});

it('denies team member from managing preview deployments', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $team = (object) ['id' => 1];
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('team')->andReturn($team);

    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();
    $preview->shouldReceive('getAttribute')->with('application')->andReturn($application);

    $policy = new ApplicationPreviewPolicy;
    expect($policy->manageDeployments($user, $preview))->toBeFalse();
});

it('denies restoring application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();

    $policy = new ApplicationPreviewPolicy;
    expect($policy->restore($user, $preview))->toBeFalse();
});

it('denies force deleting application preview', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $preview = Mockery::mock(ApplicationPreview::class)->makePartial();

    $policy = new ApplicationPreviewPolicy;
    expect($policy->forceDelete($user, $preview))->toBeFalse();
});
