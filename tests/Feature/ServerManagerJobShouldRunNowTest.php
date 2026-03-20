<?php

use App\Jobs\ServerManagerJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('catches delayed sentinel restart when job runs past midnight', function () {
    // Simulate previous dispatch yesterday at midnight
    Cache::put('sentinel-restart:1', Carbon::create(2026, 2, 27, 0, 0, 0, 'UTC')->toIso8601String(), 86400);

    // Job runs 3 minutes late at 00:03
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 0, 3, 0, 'UTC'));

    $job = new ServerManagerJob;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // isDue() would return false at 00:03, but getPreviousRunDate() = 00:00 today
    // lastDispatched = yesterday 00:00 → today 00:00 > yesterday → fires
    $result = $method->invoke($job, '0 0 * * *', 'UTC', 'sentinel-restart:1');

    expect($result)->toBeTrue();
});

it('catches delayed weekly patch check when job runs past the cron minute', function () {
    // Simulate previous dispatch last Sunday at midnight
    Cache::put('server-patch-check:1', Carbon::create(2026, 2, 22, 0, 0, 0, 'UTC')->toIso8601String(), 86400);

    // This Sunday at 00:02 — job was delayed 2 minutes
    // 2026-03-01 is a Sunday
    Carbon::setTestNow(Carbon::create(2026, 3, 1, 0, 2, 0, 'UTC'));

    $job = new ServerManagerJob;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    $result = $method->invoke($job, '0 0 * * 0', 'UTC', 'server-patch-check:1');

    expect($result)->toBeTrue();
});

it('catches delayed storage check when job runs past the cron minute', function () {
    // Simulate previous dispatch yesterday at 23:00
    Cache::put('server-storage-check:5', Carbon::create(2026, 2, 27, 23, 0, 0, 'UTC')->toIso8601String(), 86400);

    // Today at 23:04 — job was delayed 4 minutes
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 23, 4, 0, 'UTC'));

    $job = new ServerManagerJob;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    $result = $method->invoke($job, '0 23 * * *', 'UTC', 'server-storage-check:5');

    expect($result)->toBeTrue();
});

it('does not double-dispatch within same cron window', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 0, 0, 0, 'UTC'));

    $job = new ServerManagerJob;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    $first = $method->invoke($job, '0 0 * * *', 'UTC', 'sentinel-restart:10');
    expect($first)->toBeTrue();

    // Next minute — should NOT dispatch again
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 0, 1, 0, 'UTC'));
    $executionTimeProp->setValue($job, Carbon::now());

    $second = $method->invoke($job, '0 0 * * *', 'UTC', 'sentinel-restart:10');
    expect($second)->toBeFalse();
});
