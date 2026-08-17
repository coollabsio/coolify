<?php

use App\Models\User;
use App\Policies\NotificationPolicy;
use Illuminate\Database\Eloquent\Model;

it('allows team member to view notification settings', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->view($user, $notification))->toBeTrue();
});

it('denies non-team member from viewing notification settings', function () {
    $teams = collect([
        (object) ['id' => 2, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->view($user, $notification))->toBeFalse();
});

it('denies viewing notification settings with no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn(null);

    $policy = new NotificationPolicy;
    expect($policy->view($user, $notification))->toBeFalse();
});

it('allows team admin to update notification settings', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->update($user, $notification))->toBeTrue();
});

it('denies team member from updating notification settings', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->update($user, $notification))->toBeFalse();
});

it('denies updating notification settings with no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn(null);

    $policy = new NotificationPolicy;
    expect($policy->update($user, $notification))->toBeFalse();
});

it('allows team admin to manage notification settings', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->manage($user, $notification))->toBeTrue();
});

it('denies team member from managing notification settings', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->manage($user, $notification))->toBeFalse();
});

it('denies managing notification settings with no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn(null);

    $policy = new NotificationPolicy;
    expect($policy->manage($user, $notification))->toBeFalse();
});

it('allows team admin to send test notification', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->sendTest($user, $notification))->toBeTrue();
});

it('denies team member from sending test notification', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->sendTest($user, $notification))->toBeFalse();
});

it('denies sending test notification with no team', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn(null);

    $policy = new NotificationPolicy;
    expect($policy->sendTest($user, $notification))->toBeFalse();
});

it('allows team member to view but not update notification settings', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->view($user, $notification))->toBeTrue();
    expect($policy->update($user, $notification))->toBeFalse();
});

it('allows team admin to view and update notification settings', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);

    $notification = Mockery::mock(Model::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('team')->andReturn((object) ['id' => 1]);

    $policy = new NotificationPolicy;
    expect($policy->view($user, $notification))->toBeTrue();
    expect($policy->update($user, $notification))->toBeTrue();
});
