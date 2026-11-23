<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Environment;
use App\Models\NotificationThrottle;
use App\Models\Project;
use App\Models\ScheduledJobDelivery;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Container\ContainerRestarted;
use App\Notifications\Server\DockerCleanupFailed;
use App\Notifications\Server\Unreachable;
use App\Services\ScheduledJobDeliveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Cache::flush();
    Queue::fake();
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00', 'UTC'));

    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'status_change_email_notifications' => true,
    ]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('sends a throttled notification once per interval for the same resource', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);

    $this->team->notify(new ContainerRestarted('coolify-proxy', $this->server, restartedResource: $this->server));
    $this->team->notify(new ContainerRestarted('coolify-proxy', $this->server, restartedResource: $this->server));
    $this->team->notify(new ContainerRestarted('coolify-proxy', $otherServer, restartedResource: $otherServer));

    Notification::assertSentToTimes($this->team, ContainerRestarted::class, 2);

    Carbon::setTestNow(now()->addMinutes(59));
    $this->team->notify(new ContainerRestarted('coolify-proxy', $this->server, restartedResource: $this->server));
    Notification::assertSentToTimes($this->team, ContainerRestarted::class, 2);

    Carbon::setTestNow(now()->addMinutes(2));
    $this->team->notify(new ContainerRestarted('coolify-proxy', $this->server, restartedResource: $this->server));
    Notification::assertSentToTimes($this->team, ContainerRestarted::class, 3);
});

it('does not throttle a notification without a throttle subject', function () {
    $this->team->notify(new ContainerRestarted('TCP Proxy for db has been disabled', $this->server));
    $this->team->notify(new ContainerRestarted('TCP Proxy for db has been disabled', $this->server));

    Notification::assertSentToTimes($this->team, ContainerRestarted::class, 2);
});

it('sends one TCP proxy restart notification per hour while the proxy does not stay up', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $database = StandalonePostgresql::create([
        'name' => 'public-db',
        'postgres_user' => 'postgres',
        'postgres_password' => encrypt('password'),
        'postgres_db' => 'app',
        'image' => 'postgres:16-alpine',
        'is_public' => true,
        'public_port' => 54321,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    // The database runs, but its "-proxy" container is missing on every push.
    $push = fn () => (new PushServerUpdateJob($this->server, [
        'containers' => [[
            'name' => $database->uuid,
            'state' => 'running',
            'health_status' => 'healthy',
            'labels' => ['coolify.managed' => true, 'com.docker.compose.service' => $database->uuid],
        ]],
        'filesystem_usage_root' => ['used_percentage' => 10],
    ]))->handle();

    $push();
    Carbon::setTestNow(now()->addMinute());
    $push();
    Carbon::setTestNow(now()->addMinute());
    $push();

    Notification::assertSentToTimes($this->team, ContainerRestarted::class, 1);

    Carbon::setTestNow(now()->addHour());
    $push();

    Notification::assertSentToTimes($this->team, ContainerRestarted::class, 2);
});

it('does not count a throttled notification while all its channels are off', function () {
    $this->team->emailNotificationSettings()->update(['status_change_email_notifications' => false]);

    $this->team->notify(new ContainerRestarted('coolify-proxy', $this->server, restartedResource: $this->server));

    expect(NotificationThrottle::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $this->team->emailNotificationSettings()->update(['status_change_email_notifications' => true]);
    $this->team->refresh();
    $this->team->notify(new ContainerRestarted('coolify-proxy', $this->server, restartedResource: $this->server));

    Notification::assertSentToTimes($this->team, ContainerRestarted::class, 1);
    expect(NotificationThrottle::query()->count())->toBe(1);
});

it('does not mark a server as notified unreachable while the unreachable channels are off', function () {
    $this->team->emailNotificationSettings()->update(['server_unreachable_email_notifications' => false]);

    $this->server->sendUnreachableNotification();

    expect($this->server->fresh()->unreachable_notification_sent)->toBeFalse();
    Notification::assertNothingSent();

    $this->team->emailNotificationSettings()->update(['server_unreachable_email_notifications' => true]);
    $this->team->refresh();
    $this->server->refresh();
    $this->server->sendUnreachableNotification();

    Notification::assertSentToTimes($this->team, Unreachable::class, 1);
    expect($this->server->fresh()->unreachable_notification_sent)->toBeTrue();
});

it('does not count a missed scheduled run notification while its channels are off', function () {
    $this->team->emailNotificationSettings()->update(['docker_cleanup_failure_email_notifications' => false]);
    $missedCleanup = fn () => ScheduledJobDelivery::create([
        'schedule_key' => "docker-cleanup:{$this->server->id}",
        'scheduled_for' => now()->startOfMinute(),
        'job_type' => 'docker-cleanup',
        'resource_id' => $this->server->id,
        'status' => 'enqueued',
        'enqueued_at' => now()->subMinutes(ScheduledJobDeliveryService::ENQUEUED_STALE_AFTER_MINUTES + 1),
    ]);

    $missedCleanup();
    app(ScheduledJobDeliveryService::class)->recoverStaleEnqueued();

    expect(NotificationThrottle::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $this->team->emailNotificationSettings()->update(['docker_cleanup_failure_email_notifications' => true]);
    $this->team->refresh();
    Carbon::setTestNow(now()->addMinute());
    $missedCleanup();
    app(ScheduledJobDeliveryService::class)->recoverStaleEnqueued();

    Notification::assertSentToTimes($this->team, DockerCleanupFailed::class, 1);
});
