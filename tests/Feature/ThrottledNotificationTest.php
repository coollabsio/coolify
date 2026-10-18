<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Container\ContainerRestarted;
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
