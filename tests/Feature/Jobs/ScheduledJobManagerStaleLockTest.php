<?php

use App\Jobs\ScheduledJobManager;
use Illuminate\Support\Facades\Redis;

it('clears stale lock when TTL is -1', function () {
    $cachePrefix = config('cache.prefix');
    $lockKey = $cachePrefix.'laravel-queue-overlap:'.ScheduledJobManager::class.':scheduled-job-manager';

    $redis = Redis::connection('default');
    $redis->set($lockKey, 'stale-owner');

    expect($redis->ttl($lockKey))->toBe(-1);

    $job = new ScheduledJobManager;
    $job->middleware();

    expect($redis->exists($lockKey))->toBe(0);
});

it('preserves valid lock with positive TTL', function () {
    $cachePrefix = config('cache.prefix');
    $lockKey = $cachePrefix.'laravel-queue-overlap:'.ScheduledJobManager::class.':scheduled-job-manager';

    $redis = Redis::connection('default');
    $redis->set($lockKey, 'active-owner');
    $redis->expire($lockKey, 60);

    expect($redis->ttl($lockKey))->toBeGreaterThan(0);

    $job = new ScheduledJobManager;
    $job->middleware();

    expect($redis->exists($lockKey))->toBe(1);

    $redis->del($lockKey);
});

it('does not fail when no lock exists', function () {
    $cachePrefix = config('cache.prefix');
    $lockKey = $cachePrefix.'laravel-queue-overlap:'.ScheduledJobManager::class.':scheduled-job-manager';

    Redis::connection('default')->del($lockKey);

    $job = new ScheduledJobManager;
    $middleware = $job->middleware();

    expect($middleware)->toBeArray()->toHaveCount(1);
});
