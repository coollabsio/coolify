<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2025-01-15 12:00:00'); // Set to top of the hour for cron matching
});

afterEach(function () {
    Mockery::close();
    Carbon::setTestNow(); // Reset frozen time
});

it('dispatches CheckAndStartSentinelJob hourly for sentinel-enabled servers', function () {
    $settings = Mockery::mock(InstanceSettings::class)->makePartial();
    $settings->instance_timezone = 'UTC';
    $this->app->instance(InstanceSettings::class, $settings);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSentinelEnabled')->andReturn(true);
    $server->id = 1;
    $server->name = 'test-server';
    $server->ip = '192.168.1.100';
    $server->sentinel_updated_at = Carbon::now();
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) ['server_timezone' => 'UTC']);
    $server->shouldReceive('waitBeforeDoingSshCheck')->andReturn(120);

    $this->app->instance('server_manager_job.servers', fn () => collect([$server]));

    $job = new ServerManagerJob;
    $job->handle();

    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('does not dispatch CheckAndStartSentinelJob for servers without sentinel enabled', function () {
    $settings = Mockery::mock(InstanceSettings::class)->makePartial();
    $settings->instance_timezone = 'UTC';
    $this->app->instance(InstanceSettings::class, $settings);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSentinelEnabled')->andReturn(false);
    $server->id = 2;
    $server->name = 'test-server-no-sentinel';
    $server->ip = '192.168.1.101';
    $server->sentinel_updated_at = Carbon::now();
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) ['server_timezone' => 'UTC']);
    $server->shouldReceive('waitBeforeDoingSshCheck')->andReturn(120);

    $this->app->instance('server_manager_job.servers', fn () => collect([$server]));

    $job = new ServerManagerJob;
    $job->handle();

    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('respects server timezone when scheduling sentinel checks', function () {
    $settings = Mockery::mock(InstanceSettings::class)->makePartial();
    $settings->instance_timezone = 'UTC';
    $this->app->instance(InstanceSettings::class, $settings);

    Carbon::setTestNow('2025-01-15 17:00:00'); // 12:00 PM EST (top of hour in EST)

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSentinelEnabled')->andReturn(true);
    $server->id = 3;
    $server->name = 'test-server-est';
    $server->ip = '192.168.1.102';
    $server->sentinel_updated_at = Carbon::now();
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) ['server_timezone' => 'America/New_York']);
    $server->shouldReceive('waitBeforeDoingSshCheck')->andReturn(120);

    $this->app->instance('server_manager_job.servers', fn () => collect([$server]));

    $job = new ServerManagerJob;
    $job->handle();

    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('does not dispatch sentinel check when not at top of hour', function () {
    $settings = Mockery::mock(InstanceSettings::class)->makePartial();
    $settings->instance_timezone = 'UTC';
    $this->app->instance(InstanceSettings::class, $settings);

    Carbon::setTestNow('2025-01-15 12:30:00');

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSentinelEnabled')->andReturn(true);
    $server->id = 4;
    $server->name = 'test-server-mid-hour';
    $server->ip = '192.168.1.103';
    $server->sentinel_updated_at = Carbon::now();
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) ['server_timezone' => 'UTC']);
    $server->shouldReceive('waitBeforeDoingSshCheck')->andReturn(120);

    $this->app->instance('server_manager_job.servers', fn () => collect([$server]));

    $job = new ServerManagerJob;
    $job->handle();

    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});
