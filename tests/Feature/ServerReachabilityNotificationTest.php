<?php

use App\Events\ServerReachabilityChanged;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Server\Reachable;
use App\Notifications\Server\Unreachable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'server_unreachable_email_notifications' => true,
        'server_reachable_email_notifications' => true,
    ]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    Notification::fake();
});

it('sends Unreachable notification when threshold reached and not yet notified', function () {
    $this->server->settings()->update(['is_reachable' => false]);
    $this->server->forceFill([
        'unreachable_count' => 2,
        'unreachable_notification_sent' => false,
    ])->save();

    ServerReachabilityChanged::dispatch($this->server->fresh());

    Notification::assertSentTo($this->team, Unreachable::class);
    expect($this->server->fresh()->unreachable_notification_sent)->toBeTrue();
});

it('does not send Unreachable on first transient failure (count=1)', function () {
    $this->server->settings()->update(['is_reachable' => false]);
    $this->server->forceFill([
        'unreachable_count' => 1,
        'unreachable_notification_sent' => false,
    ])->save();

    ServerReachabilityChanged::dispatch($this->server->fresh());

    Notification::assertNothingSent();
});

it('does not send Unreachable when already notified', function () {
    $this->server->settings()->update(['is_reachable' => false]);
    $this->server->forceFill([
        'unreachable_count' => 5,
        'unreachable_notification_sent' => true,
    ])->save();

    ServerReachabilityChanged::dispatch($this->server->fresh());

    Notification::assertNothingSent();
});

it('sends Reachable notification on recovery when previously notified', function () {
    $this->server->settings()->update(['is_reachable' => true]);
    $this->server->forceFill([
        'unreachable_count' => 0,
        'unreachable_notification_sent' => true,
    ])->save();

    $fresh = $this->server->fresh();
    expect($fresh->unreachable_notification_sent)->toBeTrue();
    expect((bool) $fresh->settings->is_reachable)->toBeTrue();

    ServerReachabilityChanged::dispatch($fresh);

    Notification::assertSentTo($this->team, Reachable::class);
    expect($this->server->fresh()->unreachable_notification_sent)->toBeFalse();
});

it('does not send Reachable when never notified', function () {
    $this->server->settings()->update(['is_reachable' => true]);
    $this->server->forceFill([
        'unreachable_count' => 0,
        'unreachable_notification_sent' => false,
    ])->save();

    ServerReachabilityChanged::dispatch($this->server->fresh());

    Notification::assertNothingSent();
});

it('routes Unreachable notification through EmailChannel when email toggle is on', function () {
    $this->server->settings()->update(['is_reachable' => false]);
    $this->server->forceFill([
        'unreachable_count' => 2,
        'unreachable_notification_sent' => false,
    ])->save();

    ServerReachabilityChanged::dispatch($this->server->fresh());

    Notification::assertSentTo($this->team, Unreachable::class, function ($notification, $channels) {
        return in_array(EmailChannel::class, $channels);
    });
});
