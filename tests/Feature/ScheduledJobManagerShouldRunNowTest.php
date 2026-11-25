<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('dispatches backup when job runs on time at the cron minute', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));

    $result = shouldRunCronNow('0 2 * * *', 'UTC', 'test-backup:1');

    expect($result)->toBeTrue();
});

it('catches delayed job when cache has a baseline from previous run', function () {
    Cache::put('test-backup:1', Carbon::create(2026, 2, 27, 2, 0, 0, 'UTC')->toIso8601String(), 86400);

    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 7, 0, 'UTC'));

    // isDue() would return false at 02:07, but getPreviousRunDate() = 02:00 today
    // lastDispatched = 02:00 yesterday → 02:00 today > yesterday → fires
    $result = shouldRunCronNow('0 2 * * *', 'UTC', 'test-backup:1');

    expect($result)->toBeTrue();
});

it('does not double-dispatch on subsequent runs within same cron window', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));

    $first = shouldRunCronNow('0 2 * * *', 'UTC', 'test-backup:2');
    expect($first)->toBeTrue();

    // Second run at 02:01 — should NOT dispatch (previousDue=02:00, lastDispatched=02:00)
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 1, 0, 'UTC'));

    $second = shouldRunCronNow('0 2 * * *', 'UTC', 'test-backup:2');
    expect($second)->toBeFalse();
});

it('fires every_minute cron correctly on consecutive minutes', function () {
    // Minute 1
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 0, 0, 'UTC'));
    expect(shouldRunCronNow('* * * * *', 'UTC', 'test-backup:3'))->toBeTrue();

    // Minute 2
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 1, 0, 'UTC'));
    expect(shouldRunCronNow('* * * * *', 'UTC', 'test-backup:3'))->toBeTrue();

    // Minute 3
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 2, 0, 'UTC'));
    expect(shouldRunCronNow('* * * * *', 'UTC', 'test-backup:3'))->toBeTrue();
});

it('does not fire non-due jobs on restart when cache is empty', function () {
    // Time is 10:00, cron is daily at 02:00 — NOT due right now
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 0, 0, 'UTC'));

    $result = shouldRunCronNow('0 2 * * *', 'UTC', 'test-backup:4');
    expect($result)->toBeFalse();
});

it('fires due jobs on restart when cache is empty', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));

    $result = shouldRunCronNow('0 2 * * *', 'UTC', 'test-backup:4b');
    expect($result)->toBeTrue();
});

it('does not dispatch when cron is not due and was not recently due', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 0, 0, 'UTC'));

    Cache::put('test-backup:5', Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC')->toIso8601String(), 86400);

    $result = shouldRunCronNow('0 2 * * *', 'UTC', 'test-backup:5');
    expect($result)->toBeFalse();
});

it('falls back to isDue when no dedup key is provided', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));
    expect(shouldRunCronNow('0 2 * * *', 'UTC'))->toBeTrue();

    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 1, 0, 'UTC'));
    expect(shouldRunCronNow('0 2 * * *', 'UTC'))->toBeFalse();
});

it('catches delayed docker cleanup when job runs past the cron minute', function () {
    Cache::put('docker-cleanup:42', Carbon::create(2026, 2, 28, 10, 10, 0, 'UTC')->toIso8601String(), 86400);

    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 22, 0, 'UTC'));

    // isDue() would return false at :22, but getPreviousRunDate() = :20
    // lastDispatched = :10 → :20 > :10 → fires
    $result = shouldRunCronNow('*/10 * * * *', 'UTC', 'docker-cleanup:42');

    expect($result)->toBeTrue();
});

it('does not double-dispatch docker cleanup within same cron window', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 10, 0, 'UTC'));

    $first = shouldRunCronNow('*/10 * * * *', 'UTC', 'docker-cleanup:99');
    expect($first)->toBeTrue();

    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 11, 0, 'UTC'));

    $second = shouldRunCronNow('*/10 * * * *', 'UTC', 'docker-cleanup:99');
    expect($second)->toBeFalse();
});

it('seeds cache with previousDue when not due on first run', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 0, 0, 'UTC'));

    $result = shouldRunCronNow('0 2 * * *', 'UTC', 'test-seed:1');
    expect($result)->toBeFalse();

    // Verify cache was seeded with previousDue (02:00 today)
    $cached = Cache::get('test-seed:1');
    expect($cached)->not->toBeNull();
    expect(Carbon::parse($cached)->format('H:i'))->toBe('02:00');
});

it('catches next occurrence after cache was seeded on non-due first run', function () {
    // Step 1: 10:00 — not due, but seeds cache with previousDue (02:00 today)
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 10, 0, 0, 'UTC'));
    expect(shouldRunCronNow('0 2 * * *', 'UTC', 'test-seed:2'))->toBeFalse();

    // Step 2: Next day at 02:03 — delayed 3 minutes past cron.
    // previousDue = 02:00 Mar 1, lastDispatched = 02:00 Feb 28 → fires
    Carbon::setTestNow(Carbon::create(2026, 3, 1, 2, 3, 0, 'UTC'));
    expect(shouldRunCronNow('0 2 * * *', 'UTC', 'test-seed:2'))->toBeTrue();
});

it('cache survives 29 days with static 30-day TTL', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC'));

    shouldRunCronNow('0 2 * * *', 'UTC', 'test-ttl:static');
    expect(Cache::get('test-ttl:static'))->not->toBeNull();

    // 29 days later — cache (30-day TTL) should still exist
    Carbon::setTestNow(Carbon::create(2026, 3, 29, 0, 0, 0, 'UTC'));
    expect(Cache::get('test-ttl:static'))->not->toBeNull();
});

it('respects server timezone for cron evaluation', function () {
    // UTC time is 22:00 Feb 28, which is 06:00 Mar 1 in Asia/Singapore (+8)
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 22, 0, 0, 'UTC'));

    Cache::put('test-backup:7', Carbon::create(2026, 2, 28, 6, 0, 0, 'UTC')->toIso8601String(), 86400);

    // Cron "0 6 * * *" in Asia/Singapore: local time is 06:00 Mar 1 → new window → should fire
    expect(shouldRunCronNow('0 6 * * *', 'Asia/Singapore', 'test-backup:6'))->toBeTrue();

    // Cron "0 6 * * *" in UTC: previousDue = 06:00 Feb 28, already dispatched → should NOT fire
    expect(shouldRunCronNow('0 6 * * *', 'UTC', 'test-backup:7'))->toBeFalse();
});

it('passes explicit execution time instead of using Carbon::now()', function () {
    // Real "now" is irrelevant — we pass an explicit execution time
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 15, 0, 0, 'UTC'));

    $executionTime = Carbon::create(2026, 2, 28, 2, 0, 0, 'UTC');
    $result = shouldRunCronNow('0 2 * * *', 'UTC', 'test-exec-time:1', $executionTime);

    expect($result)->toBeTrue();
});
