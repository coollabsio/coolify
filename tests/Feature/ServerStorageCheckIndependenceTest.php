<?php

use App\Jobs\ServerCheckJob;
use App\Jobs\ServerManagerJob;
use App\Jobs\ServerStorageCheckJob;
use App\Models\Server;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('does not dispatch storage check when sentinel is in sync', function () {
    // When: ServerManagerJob runs at 11 PM
    Carbon::setTestNow(Carbon::parse('2025-01-15 23:00:00', 'UTC'));

    // Given: A server with Sentinel recently updated (in sync)
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now(),
    ]);

    $server->settings->update([
        'server_disk_usage_check_frequency' => '0 23 * * *',
        'server_timezone' => 'UTC',
    ]);
    $job = new ServerManagerJob;
    $job->handle();

    // Then: ServerStorageCheckJob should NOT be dispatched (Sentinel handles it via PushServerUpdateJob)
    Queue::assertNotPushed(ServerStorageCheckJob::class);
});

it('dispatches storage check when sentinel is out of sync', function () {
    // When: ServerManagerJob runs at 11 PM
    Carbon::setTestNow(Carbon::parse('2025-01-15 23:00:00', 'UTC'));

    // Given: A server with Sentinel out of sync (last update 10 minutes ago)
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now()->subMinutes(10),
    ]);

    $server->settings->update([
        'server_disk_usage_check_frequency' => '0 23 * * *',
        'server_timezone' => 'UTC',
    ]);
    $job = new ServerManagerJob;
    $job->handle();

    // Then: Both ServerCheckJob and ServerStorageCheckJob should be dispatched
    Queue::assertPushed(ServerCheckJob::class);
    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('dispatches storage check when sentinel is disabled', function () {
    // When: ServerManagerJob runs at 11 PM
    Carbon::setTestNow(Carbon::parse('2025-01-15 23:00:00', 'UTC'));

    // Given: A server with Sentinel disabled
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now()->subHours(24),
    ]);

    $server->settings->update([
        'server_disk_usage_check_frequency' => '0 23 * * *',
        'server_timezone' => 'UTC',
        'is_metrics_enabled' => false,
    ]);
    $job = new ServerManagerJob;
    $job->handle();

    // Then: ServerStorageCheckJob should be dispatched
    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('respects custom hourly storage check frequency when sentinel is out of sync', function () {
    // When: ServerManagerJob runs at the top of the hour (23:00)
    Carbon::setTestNow(Carbon::parse('2025-01-15 23:00:00', 'UTC'));

    // Given: A server with hourly storage check frequency and Sentinel out of sync
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now()->subMinutes(10),
    ]);

    $server->settings->update([
        'server_disk_usage_check_frequency' => '0 * * * *',
        'server_timezone' => 'UTC',
    ]);
    $job = new ServerManagerJob;
    $job->handle();

    // Then: ServerStorageCheckJob should be dispatched
    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('handles VALID_CRON_STRINGS mapping correctly when sentinel is out of sync', function () {
    // When: ServerManagerJob runs at the top of the hour
    Carbon::setTestNow(Carbon::parse('2025-01-15 23:00:00', 'UTC'));

    // Given: A server with 'hourly' string (should be converted to '0 * * * *') and Sentinel out of sync
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now()->subMinutes(10),
    ]);

    $server->settings->update([
        'server_disk_usage_check_frequency' => 'hourly',
        'server_timezone' => 'UTC',
    ]);
    $job = new ServerManagerJob;
    $job->handle();

    // Then: ServerStorageCheckJob should be dispatched (hourly was converted to cron)
    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('respects server timezone for storage checks when sentinel is out of sync', function () {
    // When: ServerManagerJob runs at 11 PM New York time (4 AM UTC next day)
    Carbon::setTestNow(Carbon::parse('2025-01-16 04:00:00', 'UTC'));

    // Given: A server in America/New_York timezone (UTC-5) configured for 11 PM local time and Sentinel out of sync
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now()->subMinutes(10),
    ]);

    $server->settings->update([
        'server_disk_usage_check_frequency' => '0 23 * * *',
        'server_timezone' => 'America/New_York',
    ]);
    $job = new ServerManagerJob;
    $job->handle();

    // Then: ServerStorageCheckJob should be dispatched
    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('does not dispatch storage check outside schedule', function () {
    // When: ServerManagerJob runs at 10 PM (not 11 PM)
    Carbon::setTestNow(Carbon::parse('2025-01-15 22:00:00', 'UTC'));

    // Given: A server with daily storage check at 11 PM
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now(),
    ]);

    $server->settings->update([
        'server_disk_usage_check_frequency' => '0 23 * * *',
        'server_timezone' => 'UTC',
    ]);
    $job = new ServerManagerJob;
    $job->handle();

    // Then: ServerStorageCheckJob should NOT be dispatched
    Queue::assertNotPushed(ServerStorageCheckJob::class);
});
