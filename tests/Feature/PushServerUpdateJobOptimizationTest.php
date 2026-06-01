<?php

use App\Jobs\ConnectProxyToNetworksJob;
use App\Jobs\PushServerUpdateJob;
use App\Jobs\ServerStorageCheckJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Cache::flush();
});

it('dispatches storage check when disk percentage changes above threshold', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Default notification threshold is 80%.
    $data = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 85],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id && $job->percentage === 85;
    });
});

it('does not dispatch storage check when disk usage is below threshold', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // 45% is well below the default 80% notification threshold — nothing to do.
    $data = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 45],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertNotPushed(ServerStorageCheckJob::class);
});

it('clears stale storage cache when disk usage drops below threshold', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $storageCacheKey = 'storage-check:'.$server->id;

    Cache::put($storageCacheKey, 85, 600);

    $belowThresholdData = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 45],
    ];

    $job = new PushServerUpdateJob($server, $belowThresholdData);
    $job->handle();

    Queue::assertNotPushed(ServerStorageCheckJob::class);
    expect(Cache::missing($storageCacheKey))->toBeTrue();

    Queue::fake();

    $aboveThresholdData = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 85],
    ];

    $job = new PushServerUpdateJob($server, $aboveThresholdData);
    $job->handle();

    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id && $job->percentage === 85;
    });
});

it('does not dispatch storage check when disk percentage is unchanged', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Simulate a previous push that cached the percentage (above threshold).
    Cache::put('storage-check:'.$server->id, 85, 600);

    $data = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 85],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertNotPushed(ServerStorageCheckJob::class);
});

it('dispatches storage check when disk percentage changes from cached value', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Simulate a previous push that cached 85% (above threshold).
    Cache::put('storage-check:'.$server->id, 85, 600);

    $data = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 90],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id && $job->percentage === 90;
    });
});

it('rate-limits ConnectProxyToNetworksJob dispatch to every 10 minutes', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    // First push: should dispatch ConnectProxyToNetworksJob
    $containersWithProxy = [
        [
            'name' => 'coolify-proxy',
            'state' => 'running',
            'health_status' => 'healthy',
            'labels' => ['coolify.managed' => true],
        ],
    ];

    $data = [
        'containers' => $containersWithProxy,
        'filesystem_usage_root' => ['used_percentage' => 10],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertPushed(ConnectProxyToNetworksJob::class, 1);

    // Second push: should NOT dispatch ConnectProxyToNetworksJob (rate-limited)
    Queue::fake();
    $job2 = new PushServerUpdateJob($server, $data);
    $job2->handle();

    Queue::assertNotPushed(ConnectProxyToNetworksJob::class);
});

it('dispatches ConnectProxyToNetworksJob again after cache expires', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $containersWithProxy = [
        [
            'name' => 'coolify-proxy',
            'state' => 'running',
            'health_status' => 'healthy',
            'labels' => ['coolify.managed' => true],
        ],
    ];

    $data = [
        'containers' => $containersWithProxy,
        'filesystem_usage_root' => ['used_percentage' => 10],
    ];

    // First push
    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertPushed(ConnectProxyToNetworksJob::class, 1);

    // Clear cache to simulate expiration
    Cache::forget('connect-proxy:'.$server->id);

    // Next push: should dispatch again
    Queue::fake();
    $job2 = new PushServerUpdateJob($server, $data);
    $job2->handle();

    Queue::assertPushed(ConnectProxyToNetworksJob::class, 1);
});

it('respects the configured proxy connect interval', function () {
    // Interval 0 → the connect-proxy gate key expires immediately, so every
    // push re-dispatches without a manual Cache::forget. Proves the TTL is
    // driven by config('constants.proxy.connect_networks_interval_seconds').
    config(['constants.proxy.connect_networks_interval_seconds' => 0]);

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $data = [
        'containers' => [
            [
                'name' => 'coolify-proxy',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => ['coolify.managed' => true],
            ],
        ],
        'filesystem_usage_root' => ['used_percentage' => 10],
    ];

    (new PushServerUpdateJob($server, $data))->handle();
    Queue::assertPushed(ConnectProxyToNetworksJob::class, 1);

    Queue::fake();
    (new PushServerUpdateJob($server, $data))->handle();
    Queue::assertPushed(ConnectProxyToNetworksJob::class, 1);
});

it('uses default queue for PushServerUpdateJob', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $job = new PushServerUpdateJob($server, ['containers' => []]);

    expect($job->queue)->toBeNull();
});
