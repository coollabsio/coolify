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

it('dispatches storage check when disk percentage changes', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $data = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 45],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id && $job->percentage === 45;
    });
});

it('does not dispatch storage check when disk percentage is unchanged', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Simulate a previous push that cached the percentage
    Cache::put('storage-check:'.$server->id, 45, 600);

    $data = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 45],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertNotPushed(ServerStorageCheckJob::class);
});

it('dispatches storage check when disk percentage changes from cached value', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Simulate a previous push that cached 45%
    Cache::put('storage-check:'.$server->id, 45, 600);

    $data = [
        'containers' => [],
        'filesystem_usage_root' => ['used_percentage' => 50],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    Queue::assertPushed(ServerStorageCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id && $job->percentage === 50;
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

it('uses default queue for PushServerUpdateJob', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $job = new PushServerUpdateJob($server, ['containers' => []]);

    expect($job->queue)->toBeNull();
});
