<?php

use App\Jobs\ScheduledJobManager;
use Illuminate\Support\Facades\Redis;

function scheduledJobManagerLockKey(): string
{
    return config('cache.prefix').'laravel-queue-overlap:'.ScheduledJobManager::class.':scheduled-job-manager';
}

it('clears stale lock when TTL is -1', function () {
    $lockKey = scheduledJobManagerLockKey();

    $redis = Mockery::mock();
    $redis->shouldReceive('ttl')->once()->with($lockKey)->andReturn(-1);
    $redis->shouldReceive('del')->once()->with($lockKey)->andReturn(1);

    Redis::shouldReceive('connection')->with('default')->andReturn($redis);

    $job = new ScheduledJobManager;
    $job->middleware();
});

it('preserves valid lock with positive TTL', function () {
    $lockKey = scheduledJobManagerLockKey();

    $redis = Mockery::mock();
    $redis->shouldReceive('ttl')->once()->with($lockKey)->andReturn(60);
    $redis->shouldNotReceive('del');

    Redis::shouldReceive('connection')->with('default')->andReturn($redis);

    $job = new ScheduledJobManager;
    $job->middleware();
});

it('does not fail when no lock exists', function () {
    $lockKey = scheduledJobManagerLockKey();

    $redis = Mockery::mock();
    $redis->shouldReceive('ttl')->once()->with($lockKey)->andReturn(-2);
    $redis->shouldNotReceive('del');

    Redis::shouldReceive('connection')->with('default')->andReturn($redis);

    $job = new ScheduledJobManager;
    $middleware = $job->middleware();

    expect($middleware)->toBeArray()->toHaveCount(1);
});
