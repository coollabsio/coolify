<?php

use App\Jobs\ScheduledJobManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Clear any dedup keys
    Cache::flush();
});

it('dispatches backup when job runs on time at the cron minute', function () {
    // Freeze time at exactly 02:00 — daily cron "0 2 * * *" is due
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));

    $job = new ScheduledJobManager;

    // Use reflection to test shouldRunNow
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    $result = $method->invoke($job, '0 2 * * *', 'UTC', 'test-backup:1');

    expect($result)->toBeTrue();
});

it('catches delayed job when cache has a baseline from previous run', function () {
    // Simulate a previous dispatch yesterday at 02:00
    Cache::put('test-backup:1', Carbon::create(2026, 2, 27, 2, 0, 0, 'UTC')->toIso8601String(), 86400);

    // Freeze time at 02:07 — job was delayed 7 minutes past today's 02:00 cron
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 7, 0, 'UTC'));

    $job = new ScheduledJobManager;

    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // isDue() would return false at 02:07, but getPreviousRunDate() = 02:00 today
    // lastDispatched = 02:00 yesterday → 02:00 today > yesterday → fires
    $result = $method->invoke($job, '0 2 * * *', 'UTC', 'test-backup:1');

    expect($result)->toBeTrue();
});

it('does not double-dispatch on subsequent runs within same cron window', function () {
    // First run at 02:00 — dispatches and sets cache
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));

    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    $first = $method->invoke($job, '0 2 * * *', 'UTC', 'test-backup:2');
    expect($first)->toBeTrue();

    // Second run at 02:01 — should NOT dispatch (previousDue=02:00, lastDispatched=02:00)
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 1, 0, 'UTC'));
    $executionTimeProp->setValue($job, Carbon::now());

    $second = $method->invoke($job, '0 2 * * *', 'UTC', 'test-backup:2');
    expect($second)->toBeFalse();
});

it('fires every_minute cron correctly on consecutive minutes', function () {
    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // Minute 1
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 0, 0, 'UTC'));
    $executionTimeProp->setValue($job, Carbon::now());
    $result1 = $method->invoke($job, '* * * * *', 'UTC', 'test-backup:3');
    expect($result1)->toBeTrue();

    // Minute 2
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 1, 0, 'UTC'));
    $executionTimeProp->setValue($job, Carbon::now());
    $result2 = $method->invoke($job, '* * * * *', 'UTC', 'test-backup:3');
    expect($result2)->toBeTrue();

    // Minute 3
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 2, 0, 'UTC'));
    $executionTimeProp->setValue($job, Carbon::now());
    $result3 = $method->invoke($job, '* * * * *', 'UTC', 'test-backup:3');
    expect($result3)->toBeTrue();
});

it('does not fire non-due jobs on restart when cache is empty', function () {
    // Time is 10:00, cron is daily at 02:00 — NOT due right now
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 0, 0, 'UTC'));

    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // Cache is empty (fresh restart) — should NOT fire daily backup at 10:00
    $result = $method->invoke($job, '0 2 * * *', 'UTC', 'test-backup:4');
    expect($result)->toBeFalse();
});

it('fires due jobs on restart when cache is empty', function () {
    // Time is exactly 02:00, cron is daily at 02:00 — IS due right now
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));

    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // Cache is empty (fresh restart) — but cron IS due → should fire
    $result = $method->invoke($job, '0 2 * * *', 'UTC', 'test-backup:4b');
    expect($result)->toBeTrue();
});

it('does not dispatch when cron is not due and was not recently due', function () {
    // Time is 10:00, cron is daily at 02:00 — last due was 8 hours ago
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 0, 0, 'UTC'));

    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // previousDue = 02:00, but lastDispatched was set at 02:00 (simulate)
    Cache::put('test-backup:5', Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC')->toIso8601String(), 86400);

    $result = $method->invoke($job, '0 2 * * *', 'UTC', 'test-backup:5');
    expect($result)->toBeFalse();
});

it('falls back to isDue when no dedup key is provided', function () {
    // Time is exactly 02:00, cron is "0 2 * * *" — should be due
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));

    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // No dedup key → simple isDue check
    $result = $method->invoke($job, '0 2 * * *', 'UTC');
    expect($result)->toBeTrue();

    // At 02:01 without dedup key → isDue returns false
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 1, 0, 'UTC'));
    $executionTimeProp->setValue($job, Carbon::now());

    $result2 = $method->invoke($job, '0 2 * * *', 'UTC');
    expect($result2)->toBeFalse();
});

it('catches delayed docker cleanup when job runs past the cron minute', function () {
    // Simulate a previous dispatch at :10
    Cache::put('docker-cleanup:42', Carbon::create(2026, 2, 28, 10, 10, 0, 'UTC')->toIso8601String(), 86400);

    // Freeze time at :22 — job was delayed 2 minutes past the :20 cron window
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 22, 0, 'UTC'));

    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // isDue() would return false at :22, but getPreviousRunDate() = :20
    // lastDispatched = :10 → :20 > :10 → fires
    $result = $method->invoke($job, '*/10 * * * *', 'UTC', 'docker-cleanup:42');

    expect($result)->toBeTrue();
});

it('does not double-dispatch docker cleanup within same cron window', function () {
    // First dispatch at :10
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 10, 0, 'UTC'));

    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    $first = $method->invoke($job, '*/10 * * * *', 'UTC', 'docker-cleanup:99');
    expect($first)->toBeTrue();

    // Second run at :11 — should NOT dispatch (previousDue=:10, lastDispatched=:10)
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 11, 0, 'UTC'));
    $executionTimeProp->setValue($job, Carbon::now());

    $second = $method->invoke($job, '*/10 * * * *', 'UTC', 'docker-cleanup:99');
    expect($second)->toBeFalse();
});

it('respects server timezone for cron evaluation', function () {
    // UTC time is 22:00 Feb 28, which is 06:00 Mar 1 in Asia/Singapore (+8)
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 22, 0, 0, 'UTC'));

    $job = new ScheduledJobManager;
    $reflection = new ReflectionClass($job);

    $executionTimeProp = $reflection->getProperty('executionTime');
    $executionTimeProp->setAccessible(true);
    $executionTimeProp->setValue($job, Carbon::now());

    $method = $reflection->getMethod('shouldRunNow');
    $method->setAccessible(true);

    // Simulate that today's 06:00 UTC run was already dispatched (at 06:00 UTC)
    Cache::put('test-backup:7', Carbon::create(2026, 2, 28, 6, 0, 0, 'UTC')->toIso8601String(), 86400);

    // Cron "0 6 * * *" in Asia/Singapore: local time is 06:00 Mar 1 → previousDue = 06:00 Mar 1 SGT
    // That's a NEW cron window (Mar 1) that hasn't been dispatched → should fire
    $resultSingapore = $method->invoke($job, '0 6 * * *', 'Asia/Singapore', 'test-backup:6');
    expect($resultSingapore)->toBeTrue();

    // Cron "0 6 * * *" in UTC: previousDue = 06:00 Feb 28 UTC, already dispatched at 06:00 → should NOT fire
    $resultUtc = $method->invoke($job, '0 6 * * *', 'UTC', 'test-backup:7');
    expect($resultUtc)->toBeFalse();
});
