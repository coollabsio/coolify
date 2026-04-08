<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2025-01-15 12:00:00');
});

afterEach(function () {
    Mockery::close();
    Carbon::setTestNow();
});

it('does not dispatch CheckAndStartSentinelJob hourly anymore', function () {
    $settings = Mockery::mock(InstanceSettings::class);
    $settings->instance_timezone = 'UTC';
    $this->app->instance(InstanceSettings::class, $settings);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSentinelEnabled')->andReturn(true);
    $server->shouldReceive('isSentinelLive')->andReturn(true);
    $server->id = 1;
    $server->name = 'test-server';
    $server->ip = '192.168.1.100';
    $server->sentinel_updated_at = Carbon::now();
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) ['server_timezone' => 'UTC']);
    $server->shouldReceive('waitBeforeDoingSshCheck')->andReturn(120);

    Server::shouldReceive('where')->with('ip', '!=', '1.2.3.4')->andReturnSelf();
    Server::shouldReceive('get')->andReturn(collect([$server]));

    $job = new ServerManagerJob;
    $job->handle();

    // Hourly CheckAndStartSentinelJob dispatch was removed — ServerCheckJob handles it when Sentinel is out of sync
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('skips ServerConnectionCheckJob when sentinel is live', function () {
    $settings = Mockery::mock(InstanceSettings::class);
    $settings->instance_timezone = 'UTC';
    $this->app->instance(InstanceSettings::class, $settings);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSentinelEnabled')->andReturn(true);
    $server->shouldReceive('isSentinelLive')->andReturn(true);
    $server->id = 1;
    $server->name = 'test-server';
    $server->ip = '192.168.1.100';
    $server->sentinel_updated_at = Carbon::now();
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) ['server_timezone' => 'UTC']);
    $server->shouldReceive('waitBeforeDoingSshCheck')->andReturn(120);

    Server::shouldReceive('where')->with('ip', '!=', '1.2.3.4')->andReturnSelf();
    Server::shouldReceive('get')->andReturn(collect([$server]));

    $job = new ServerManagerJob;
    $job->handle();

    // Sentinel is healthy so SSH connection check is skipped
    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});

it('dispatches ServerConnectionCheckJob when sentinel is not live', function () {
    $settings = Mockery::mock(InstanceSettings::class);
    $settings->instance_timezone = 'UTC';
    $this->app->instance(InstanceSettings::class, $settings);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSentinelEnabled')->andReturn(true);
    $server->shouldReceive('isSentinelLive')->andReturn(false);
    $server->id = 1;
    $server->name = 'test-server';
    $server->ip = '192.168.1.100';
    $server->sentinel_updated_at = Carbon::now()->subMinutes(10);
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) ['server_timezone' => 'UTC']);
    $server->shouldReceive('waitBeforeDoingSshCheck')->andReturn(120);

    Server::shouldReceive('where')->with('ip', '!=', '1.2.3.4')->andReturnSelf();
    Server::shouldReceive('get')->andReturn(collect([$server]));

    $job = new ServerManagerJob;
    $job->handle();

    // Sentinel is out of sync so SSH connection check is needed
    Queue::assertPushed(ServerConnectionCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('dispatches ServerConnectionCheckJob when sentinel is not enabled', function () {
    $settings = Mockery::mock(InstanceSettings::class);
    $settings->instance_timezone = 'UTC';
    $this->app->instance(InstanceSettings::class, $settings);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSentinelEnabled')->andReturn(false);
    $server->shouldReceive('isSentinelLive')->never();
    $server->id = 2;
    $server->name = 'test-server-no-sentinel';
    $server->ip = '192.168.1.101';
    $server->sentinel_updated_at = Carbon::now();
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) ['server_timezone' => 'UTC']);
    $server->shouldReceive('waitBeforeDoingSshCheck')->andReturn(120);

    Server::shouldReceive('where')->with('ip', '!=', '1.2.3.4')->andReturnSelf();
    Server::shouldReceive('get')->andReturn(collect([$server]));

    $job = new ServerManagerJob;
    $job->handle();

    // Sentinel is not enabled so SSH connection check must run
    Queue::assertPushed(ServerConnectionCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});
