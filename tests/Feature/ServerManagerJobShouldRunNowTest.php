<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('catches delayed sentinel restart when job runs past midnight', function () {
    Cache::put('sentinel-restart:1', Carbon::create(2026, 2, 27, 0, 0, 0, 'UTC')->toIso8601String(), 86400);

    // Job runs 3 minutes late at 00:03
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 0, 3, 0, 'UTC'));

    // isDue() would return false at 00:03, but getPreviousRunDate() = 00:00 today
    // lastDispatched = yesterday 00:00 → today 00:00 > yesterday → fires
    $result = shouldRunCronNow('0 0 * * *', 'UTC', 'sentinel-restart:1');

    expect($result)->toBeTrue();
});

it('catches delayed weekly patch check when job runs past the cron minute', function () {
    Cache::put('server-patch-check:1', Carbon::create(2026, 2, 22, 0, 0, 0, 'UTC')->toIso8601String(), 86400);

    // This Sunday at 00:02 — job was delayed 2 minutes (2026-03-01 is a Sunday)
    Carbon::setTestNow(Carbon::create(2026, 3, 1, 0, 2, 0, 'UTC'));

    $result = shouldRunCronNow('0 0 * * 0', 'UTC', 'server-patch-check:1');

    expect($result)->toBeTrue();
});

it('catches delayed storage check when job runs past the cron minute', function () {
    Cache::put('server-storage-check:5', Carbon::create(2026, 2, 27, 23, 0, 0, 'UTC')->toIso8601String(), 86400);

    Carbon::setTestNow(Carbon::create(2026, 2, 28, 23, 4, 0, 'UTC'));

    $result = shouldRunCronNow('0 23 * * *', 'UTC', 'server-storage-check:5');

    expect($result)->toBeTrue();
});

it('seeds cache on non-due first run so weekly catch-up works', function () {
    // Wednesday at 10:00 — weekly cron (Sunday 00:00) is not due
    Carbon::setTestNow(Carbon::create(2026, 2, 25, 10, 0, 0, 'UTC'));

    $result = shouldRunCronNow('0 0 * * 0', 'UTC', 'server-patch-check:seed-test');
    expect($result)->toBeFalse();

    // Verify cache was seeded
    expect(Cache::get('server-patch-check:seed-test'))->not->toBeNull();

    // Next Sunday at 00:02 — delayed 2 minutes past cron
    // Catch-up: previousDue = Mar 1 00:00, lastDispatched = Feb 22 → fires
    Carbon::setTestNow(Carbon::create(2026, 3, 1, 0, 2, 0, 'UTC'));

    $result2 = shouldRunCronNow('0 0 * * 0', 'UTC', 'server-patch-check:seed-test');
    expect($result2)->toBeTrue();
});

it('daily cron fires after cache seed even when delayed past the minute', function () {
    // Step 1: 15:00 — not due for midnight cron, but seeds cache
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 15, 0, 0, 'UTC'));

    $result1 = shouldRunCronNow('0 0 * * *', 'UTC', 'sentinel-restart:seed-test');
    expect($result1)->toBeFalse();

    // Step 2: Next day at 00:05 — delayed 5 minutes past midnight
    // Catch-up: previousDue = Mar 1 00:00, lastDispatched = Feb 28 00:00 → fires
    Carbon::setTestNow(Carbon::create(2026, 3, 1, 0, 5, 0, 'UTC'));

    $result2 = shouldRunCronNow('0 0 * * *', 'UTC', 'sentinel-restart:seed-test');
    expect($result2)->toBeTrue();
});

it('does not double-dispatch within same cron window', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 0, 0, 0, 'UTC'));

    $first = shouldRunCronNow('0 0 * * *', 'UTC', 'sentinel-restart:10');
    expect($first)->toBeTrue();

    // Next minute — should NOT dispatch again
    Carbon::setTestNow(Carbon::create(2026, 2, 28, 0, 1, 0, 'UTC'));

    $second = shouldRunCronNow('0 0 * * *', 'UTC', 'sentinel-restart:10');
    expect($second)->toBeFalse();
});
