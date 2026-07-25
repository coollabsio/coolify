<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('runs only on matching day when day condition is set', function () {
    // Thursday, June 4 2026 at midnight — day 4 of month and Thursday (dow=4)
    Carbon::setTestNow(Carbon::create(2026, 6, 4, 0, 0, 0, 'UTC'));

    expect(scheduled_task_is_due_now('0 0 1-7 * *', '4', Carbon::now()))->toBeTrue();
});

it('does not run on matching day of month when day condition does not match', function () {
    // Friday, June 5 2026 at midnight — day 5 of month but not Thursday
    Carbon::setTestNow(Carbon::create(2026, 6, 5, 0, 0, 0, 'UTC'));

    expect(scheduled_task_is_due_now('0 0 1-7 * *', '4', Carbon::now()))->toBeFalse();
});

it('does not run on matching day condition when day of month does not match', function () {
    // Thursday, June 11 2026 at midnight — Thursday but day 11 is outside 1-7
    Carbon::setTestNow(Carbon::create(2026, 6, 11, 0, 0, 0, 'UTC'));

    expect(scheduled_task_is_due_now('0 0 1-7 * *', '4', Carbon::now()))->toBeFalse();
});

it('falls back to standard cron when day condition is empty', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 5, 0, 0, 0, 'UTC'));

    expect(scheduled_task_is_due_now('0 0 * * 5', null, Carbon::now()))->toBeTrue();
});

it('dispatches scheduled task with day condition only once per window', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 4, 0, 0, 0, 'UTC'));

    $first = shouldRunScheduledTaskNow('0 0 1-7 * *', '4', 'UTC', 'scheduled-task:day-condition');
    expect($first)->toBeTrue();

    Carbon::setTestNow(Carbon::create(2026, 6, 4, 0, 1, 0, 'UTC'));

    $second = shouldRunScheduledTaskNow('0 0 1-7 * *', '4', 'UTC', 'scheduled-task:day-condition');
    expect($second)->toBeFalse();
});

it('catches delayed scheduled task with day condition', function () {
    Cache::put(
        'scheduled-task:day-condition-delayed',
        Carbon::create(2026, 5, 7, 0, 0, 0, 'UTC')->toIso8601String(),
        86400
    );

    Carbon::setTestNow(Carbon::create(2026, 6, 4, 0, 7, 0, 'UTC'));

    expect(shouldRunScheduledTaskNow('0 0 1-7 * *', '4', 'UTC', 'scheduled-task:day-condition-delayed'))->toBeTrue();
});

it('validates day condition expressions', function () {
    expect(validate_day_condition(null))->toBeTrue();
    expect(validate_day_condition('4'))->toBeTrue();
    expect(validate_day_condition('1-5'))->toBeTrue();
    expect(validate_day_condition('invalid-day'))->toBeFalse();
});
