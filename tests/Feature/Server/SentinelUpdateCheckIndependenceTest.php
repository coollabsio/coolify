<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    // Create user (which automatically creates a team)
    $user = User::factory()->create();
    $this->team = $user->teams()->first();

    // Create server with sentinel enabled
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    // Enable sentinel on the server
    $this->server->settings->update([
        'is_sentinel_enabled' => true,
        'server_timezone' => 'UTC',
    ]);

    $this->server->refresh();
});

afterEach(function () {
    Carbon::setTestNow(); // Reset frozen time
});

it('dispatches sentinel check hourly regardless of instance update_check_frequency setting', function () {
    // Set instance update_check_frequency to yearly (most infrequent option)
    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'update_check_frequency' => '0 0 1 1 *', // Yearly - January 1st at midnight
        'instance_timezone' => 'UTC',
    ]);

    // Set time to top of any hour (sentinel should check every hour)
    Carbon::setTestNow('2025-06-15 14:00:00'); // Random hour, not January 1st

    // Run ServerManagerJob
    $job = new ServerManagerJob;
    $job->handle();

    // Assert that CheckAndStartSentinelJob was dispatched despite yearly update check frequency
    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });
});

it('does not dispatch sentinel check when not at top of hour', function () {
    // Set instance update_check_frequency to hourly (most frequent)
    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'update_check_frequency' => '0 * * * *', // Hourly
        'instance_timezone' => 'UTC',
    ]);

    // Set time to middle of the hour (sentinel check cron won't match)
    Carbon::setTestNow('2025-06-15 14:30:00'); // 30 minutes past the hour

    // Run ServerManagerJob
    $job = new ServerManagerJob;
    $job->handle();

    // Assert that CheckAndStartSentinelJob was NOT dispatched (not top of hour)
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('dispatches sentinel check at every hour mark throughout the day', function () {
    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'update_check_frequency' => '0 0 1 1 *', // Yearly
        'instance_timezone' => 'UTC',
    ]);

    // Test multiple hours throughout a day
    $hoursToTest = [0, 6, 12, 18, 23]; // Various hours of the day

    foreach ($hoursToTest as $hour) {
        Queue::fake(); // Reset queue for each test

        Carbon::setTestNow("2025-06-15 {$hour}:00:00");

        $job = new ServerManagerJob;
        $job->handle();

        Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) {
            return $job->server->id === $this->server->id;
        }, "Failed to dispatch sentinel check at hour {$hour}");
    }
});

it('respects server timezone when checking sentinel updates', function () {
    // Update server timezone to America/New_York
    $this->server->settings->update([
        'server_timezone' => 'America/New_York',
    ]);

    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'instance_timezone' => 'UTC',
    ]);

    // Set time to 17:00 UTC which is 12:00 PM EST (top of hour in server's timezone)
    Carbon::setTestNow('2025-01-15 17:00:00');

    $job = new ServerManagerJob;
    $job->handle();

    // Should dispatch because it's top of hour in server's timezone (America/New_York)
    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });
});

it('does not dispatch sentinel check for servers without sentinel enabled', function () {
    // Disable sentinel
    $this->server->settings->update([
        'is_sentinel_enabled' => false,
    ]);

    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'update_check_frequency' => '0 * * * *',
        'instance_timezone' => 'UTC',
    ]);

    Carbon::setTestNow('2025-06-15 14:00:00');

    $job = new ServerManagerJob;
    $job->handle();

    // Should NOT dispatch because sentinel is disabled
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('handles multiple servers with different sentinel configurations', function () {
    // Create a second server with sentinel disabled
    $server2 = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $server2->settings->update([
        'is_sentinel_enabled' => false,
        'server_timezone' => 'UTC',
    ]);

    // Create a third server with sentinel enabled
    $server3 = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $server3->settings->update([
        'is_sentinel_enabled' => true,
        'server_timezone' => 'UTC',
    ]);

    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'instance_timezone' => 'UTC',
    ]);

    Carbon::setTestNow('2025-06-15 14:00:00');

    $job = new ServerManagerJob;
    $job->handle();

    // Should dispatch for server1 (sentinel enabled) and server3 (sentinel enabled)
    Queue::assertPushed(CheckAndStartSentinelJob::class, 2);

    // Verify it was dispatched for the correct servers
    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });

    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) use ($server3) {
        return $job->server->id === $server3->id;
    });
});
