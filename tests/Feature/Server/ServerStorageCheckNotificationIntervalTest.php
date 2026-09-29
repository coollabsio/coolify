<?php

use App\Jobs\ServerStorageCheckJob;
use App\Livewire\Server\Advanced;
use App\Models\InstanceSettings;
use App\Models\NotificationThrottle;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Server\HighDiskUsage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Notification::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function diskUsageIntervalServer(int $intervalHours = 24): Server
{
    $team = Team::factory()->create();
    $team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'server_disk_usage_email_notifications' => true,
    ]);
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '10.0.0.10']);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'server_disk_usage_notification_threshold' => 80,
        'server_disk_usage_notification_interval_hours' => $intervalHours,
    ]);

    return $server->fresh();
}

function runDiskUsageCheck(Server $server, int $percentage): void
{
    (new ServerStorageCheckJob($server->fresh(), $percentage))->handle();
}

it('sends one high disk usage notification per configured interval', function () {
    $server = diskUsageIntervalServer(intervalHours: 6);

    runDiskUsageCheck($server, 93);
    Carbon::setTestNow(now()->addMinutes(10));
    runDiskUsageCheck($server, 93);
    Carbon::setTestNow(now()->addHours(5));
    runDiskUsageCheck($server, 94);

    Notification::assertSentToTimes($server->team, HighDiskUsage::class, 1);

    Carbon::setTestNow(now()->addMinutes(51));
    runDiskUsageCheck($server, 94);

    Notification::assertSentToTimes($server->team, HighDiskUsage::class, 2);
});

it('defaults to one notification per 24 hours', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    expect($server->settings->fresh()->server_disk_usage_notification_interval_hours)->toBe(24);
});

it('does not notify below the threshold', function () {
    $server = diskUsageIntervalServer();

    runDiskUsageCheck($server, 50);

    Notification::assertNothingSent();
    expect(NotificationThrottle::wasSent($server, HighDiskUsage::class))->toBeFalse();
});

it('saves the notification interval from the server advanced settings', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Advanced::class, ['server_uuid' => $server->uuid])
        ->assertSet('serverDiskUsageNotificationIntervalHours', 24)
        ->set('serverDiskUsageNotificationIntervalHours', 0)
        ->call('submit')
        ->assertHasErrors(['serverDiskUsageNotificationIntervalHours'])
        ->set('serverDiskUsageNotificationIntervalHours', 12)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($server->settings->fresh()->server_disk_usage_notification_interval_hours)->toBe(12);
});

it('does not let a member change the notification interval', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Advanced::class, ['server_uuid' => $server->uuid])
        ->set('serverDiskUsageNotificationIntervalHours', 2)
        ->call('submit');

    expect($server->settings->fresh()->server_disk_usage_notification_interval_hours)->toBe(24);
});
