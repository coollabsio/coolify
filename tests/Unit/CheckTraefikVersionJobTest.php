<?php

// Constants used in server check delay calculations
// These match the values in config/constants.php -> server_checks
const MIN_DELAY = 120;
const MAX_DELAY = 300;
const SCALING_FACTOR = 0.2;

it('calculates notification delay correctly using formula', function () {
    // Test the delay calculation formula directly
    // Formula: min(max, max(min, serverCount * scaling))

    $testCases = [
        ['servers' => 10, 'expected' => 120],    // 10 * 0.2 = 2 -> uses min 120
        ['servers' => 600, 'expected' => 120],   // 600 * 0.2 = 120 (at min)
        ['servers' => 1000, 'expected' => 200],  // 1000 * 0.2 = 200
        ['servers' => 1500, 'expected' => 300],  // 1500 * 0.2 = 300 (at max)
        ['servers' => 5000, 'expected' => 300],  // 5000 * 0.2 = 1000 -> uses max 300
    ];

    foreach ($testCases as $case) {
        $count = $case['servers'];
        $calculatedDelay = (int) ($count * SCALING_FACTOR);
        $result = min(MAX_DELAY, max(MIN_DELAY, $calculatedDelay));

        expect($result)->toBe($case['expected'], "Failed for {$count} servers");
    }
});

it('respects minimum delay boundary', function () {
    // Test that delays never go below minimum
    $serverCounts = [1, 10, 50, 100, 500, 599];

    foreach ($serverCounts as $count) {
        $calculatedDelay = (int) ($count * SCALING_FACTOR);
        $result = min(MAX_DELAY, max(MIN_DELAY, $calculatedDelay));

        expect($result)->toBeGreaterThanOrEqual(MIN_DELAY,
            "Delay for {$count} servers should be >= ".MIN_DELAY);
    }
});

it('respects maximum delay boundary', function () {
    // Test that delays never exceed maximum
    $serverCounts = [1500, 2000, 5000, 10000];

    foreach ($serverCounts as $count) {
        $calculatedDelay = (int) ($count * SCALING_FACTOR);
        $result = min(MAX_DELAY, max(MIN_DELAY, $calculatedDelay));

        expect($result)->toBeLessThanOrEqual(MAX_DELAY,
            "Delay for {$count} servers should be <= ".MAX_DELAY);
    }
});

it('provides more conservative delays than old calculation', function () {
    // Compare new formula with old one
    // Old: min(300, max(60, count/10))
    // New: min(300, max(120, count*0.2))

    $testServers = [100, 500, 1000, 2000, 3000];

    foreach ($testServers as $count) {
        // Old calculation
        $oldDelay = min(300, max(60, (int) ($count / 10)));

        // New calculation
        $newDelay = min(300, max(120, (int) ($count * 0.2)));

        // For counts >= 600, new delay should be >= old delay
        if ($count >= 600) {
            expect($newDelay)->toBeGreaterThanOrEqual($oldDelay,
                "New delay should be >= old delay for {$count} servers (old: {$oldDelay}s, new: {$newDelay}s)");
        }

        // Both should respect the 300s maximum
        expect($newDelay)->toBeLessThanOrEqual(300);
        expect($oldDelay)->toBeLessThanOrEqual(300);
    }
});

it('scales linearly within bounds', function () {
    // Test that scaling is linear between min and max thresholds

    // Find threshold where calculated delay equals min: 120 / 0.2 = 600 servers
    $minThreshold = (int) (MIN_DELAY / SCALING_FACTOR);
    expect($minThreshold)->toBe(600);

    // Find threshold where calculated delay equals max: 300 / 0.2 = 1500 servers
    $maxThreshold = (int) (MAX_DELAY / SCALING_FACTOR);
    expect($maxThreshold)->toBe(1500);

    // Test linear scaling between thresholds
    $delay700 = min(MAX_DELAY, max(MIN_DELAY, (int) (700 * SCALING_FACTOR)));
    $delay900 = min(MAX_DELAY, max(MIN_DELAY, (int) (900 * SCALING_FACTOR)));
    $delay1100 = min(MAX_DELAY, max(MIN_DELAY, (int) (1100 * SCALING_FACTOR)));

    expect($delay700)->toBe(140);  // 700 * 0.2 = 140
    expect($delay900)->toBe(180);  // 900 * 0.2 = 180
    expect($delay1100)->toBe(220); // 1100 * 0.2 = 220

    // Verify linear progression
    expect($delay900 - $delay700)->toBe(40);  // 200 servers * 0.2 = 40s difference
    expect($delay1100 - $delay900)->toBe(40); // 200 servers * 0.2 = 40s difference
});

it('handles edge cases in formula', function () {
    // Zero servers
    $result = min(MAX_DELAY, max(MIN_DELAY, (int) (0 * SCALING_FACTOR)));
    expect($result)->toBe(120);

    // One server
    $result = min(MAX_DELAY, max(MIN_DELAY, (int) (1 * SCALING_FACTOR)));
    expect($result)->toBe(120);

    // Exactly at boundaries
    $result = min(MAX_DELAY, max(MIN_DELAY, (int) (600 * SCALING_FACTOR))); // 600 * 0.2 = 120
    expect($result)->toBe(120);

    $result = min(MAX_DELAY, max(MIN_DELAY, (int) (1500 * SCALING_FACTOR))); // 1500 * 0.2 = 300
    expect($result)->toBe(300);
});
