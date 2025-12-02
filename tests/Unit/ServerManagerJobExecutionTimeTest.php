<?php

use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('does not mutate Carbon instance when using copy() before subSeconds()', function () {
    // This test verifies the fix for the bug where subSeconds() was mutating executionTime
    $originalTime = Carbon::parse('2024-12-02 12:00:00');
    $originalTimeString = $originalTime->toDateTimeString();

    // Simulate what happens in processServerTasks() with the FIX applied
    $waitTime = 360;
    $threshold = $originalTime->copy()->subSeconds($waitTime);

    // The original time should remain unchanged after using copy()
    expect($originalTime->toDateTimeString())->toBe($originalTimeString);
    expect($threshold->toDateTimeString())->toBe('2024-12-02 11:54:00');
});

it('demonstrates mutation bug when not using copy()', function () {
    // This test shows what would happen WITHOUT the fix (the bug)
    $originalTime = Carbon::parse('2024-12-02 12:00:00');

    // Simulate what would happen WITHOUT copy() (the bug)
    $waitTime = 360;
    $threshold = $originalTime->subSeconds($waitTime);

    // Without copy(), the original time is mutated!
    expect($originalTime->toDateTimeString())->toBe('2024-12-02 11:54:00');
    expect($threshold->toDateTimeString())->toBe('2024-12-02 11:54:00');
    expect($originalTime)->toBe($threshold); // They're the same object
});

it('preserves executionTime across multiple subSeconds calls with copy()', function () {
    // Simulate processing multiple servers with different wait times
    $executionTime = Carbon::parse('2024-12-02 12:00:00');
    $originalTimeString = $executionTime->toDateTimeString();

    // Server 1: waitTime = 360s
    $threshold1 = $executionTime->copy()->subSeconds(360);
    expect($executionTime->toDateTimeString())->toBe($originalTimeString);
    expect($threshold1->toDateTimeString())->toBe('2024-12-02 11:54:00');

    // Server 2: waitTime = 300s (should still use original time)
    $threshold2 = $executionTime->copy()->subSeconds(300);
    expect($executionTime->toDateTimeString())->toBe($originalTimeString);
    expect($threshold2->toDateTimeString())->toBe('2024-12-02 11:55:00');

    // Server 3: waitTime = 360s (should still use original time)
    $threshold3 = $executionTime->copy()->subSeconds(360);
    expect($executionTime->toDateTimeString())->toBe($originalTimeString);
    expect($threshold3->toDateTimeString())->toBe('2024-12-02 11:54:00');

    // Server 4: waitTime = 300s (should still use original time)
    $threshold4 = $executionTime->copy()->subSeconds(300);
    expect($executionTime->toDateTimeString())->toBe($originalTimeString);
    expect($threshold4->toDateTimeString())->toBe('2024-12-02 11:55:00');

    // Server 5: waitTime = 360s (should still use original time)
    $threshold5 = $executionTime->copy()->subSeconds(360);
    expect($executionTime->toDateTimeString())->toBe($originalTimeString);
    expect($threshold5->toDateTimeString())->toBe('2024-12-02 11:54:00');

    // The executionTime should STILL be exactly the original time
    expect($executionTime->toDateTimeString())->toBe('2024-12-02 12:00:00');
});

it('demonstrates compounding bug without copy() across multiple calls', function () {
    // This shows the compounding bug that happens WITHOUT the fix
    $executionTime = Carbon::parse('2024-12-02 12:00:00');

    // Server 1: waitTime = 360s
    $threshold1 = $executionTime->subSeconds(360);
    expect($executionTime->toDateTimeString())->toBe('2024-12-02 11:54:00'); // MUTATED!

    // Server 2: waitTime = 300s (uses already-mutated time)
    $threshold2 = $executionTime->subSeconds(300);
    expect($executionTime->toDateTimeString())->toBe('2024-12-02 11:49:00'); // Further mutated!

    // Server 3: waitTime = 360s (uses even more mutated time)
    $threshold3 = $executionTime->subSeconds(360);
    expect($executionTime->toDateTimeString())->toBe('2024-12-02 11:43:00'); // Even more mutated!

    // Server 4: waitTime = 300s
    $threshold4 = $executionTime->subSeconds(300);
    expect($executionTime->toDateTimeString())->toBe('2024-12-02 11:38:00');

    // Server 5: waitTime = 360s
    $threshold5 = $executionTime->subSeconds(360);
    expect($executionTime->toDateTimeString())->toBe('2024-12-02 11:32:00');

    // The executionTime is now 1680 seconds (28 minutes) earlier than it should be!
    expect($executionTime->diffInSeconds(Carbon::parse('2024-12-02 12:00:00')))->toEqual(1680);
});

it('respects server timezone when evaluating cron schedules', function () {
    // This test verifies that timezone parameter affects cron evaluation
    // Set a fixed test time at 23:00 UTC
    Carbon::setTestNow('2024-12-02 23:00:00', 'UTC');

    $executionTime = Carbon::now();
    $cronExpression = new \Cron\CronExpression('0 23 * * *'); // Every day at 11 PM

    // Test 1: UTC timezone at 23:00 - should match
    $timeInUTC = $executionTime->copy()->setTimezone('UTC');
    expect($cronExpression->isDue($timeInUTC))->toBeTrue();

    // Test 2: America/New_York timezone - 23:00 UTC is 18:00 EST, should not match 23:00 cron
    $timeInEST = $executionTime->copy()->setTimezone('America/New_York');
    expect($cronExpression->isDue($timeInEST))->toBeFalse();

    // Test 3: Asia/Tokyo timezone - 23:00 UTC is 08:00 JST next day, should not match 23:00 cron
    $timeInJST = $executionTime->copy()->setTimezone('Asia/Tokyo');
    expect($cronExpression->isDue($timeInJST))->toBeFalse();

    // Test 4: Verify copy() preserves the original time
    expect($executionTime->toDateTimeString())->toBe('2024-12-02 23:00:00');
});
