<?php

/**
 * Tests for the downsampleLTTB helper function used for metrics downsampling.
 * This function implements the Largest-Triangle-Three-Buckets algorithm.
 */
it('returns data unchanged when below threshold', function () {
    $data = [
        [1000, 10.5],
        [2000, 20.3],
        [3000, 15.7],
    ];

    $result = downsampleLTTB($data, 1000);

    expect($result)->toBe($data);
});

it('returns data unchanged when threshold is 2 or less', function () {
    $data = [
        [1000, 10.5],
        [2000, 20.3],
        [3000, 15.7],
        [4000, 25.0],
        [5000, 12.0],
    ];

    $result = downsampleLTTB($data, 2);
    expect($result)->toBe($data);

    $result = downsampleLTTB($data, 1);
    expect($result)->toBe($data);
});

it('downsamples to target threshold count', function () {
    // Seed for reproducibility
    mt_srand(42);

    // Generate 100 data points
    $data = [];
    for ($i = 0; $i < 100; $i++) {
        $data[] = [$i * 1000, mt_rand(0, 100) / 10];
    }

    $result = downsampleLTTB($data, 10);

    expect(count($result))->toBe(10);
});

it('preserves first and last data points', function () {
    $data = [];
    for ($i = 0; $i < 100; $i++) {
        $data[] = [$i * 1000, $i * 1.5];
    }

    $result = downsampleLTTB($data, 20);

    // First point should be preserved
    expect($result[0])->toBe($data[0]);

    // Last point should be preserved
    expect(end($result))->toBe(end($data));
});

it('maintains chronological order', function () {
    $data = [];
    for ($i = 0; $i < 500; $i++) {
        $data[] = [$i * 60000, sin($i / 10) * 50 + 50]; // Sine wave pattern
    }

    $result = downsampleLTTB($data, 50);

    // Verify all timestamps are in non-decreasing order
    $previousTimestamp = -1;
    foreach ($result as $point) {
        expect($point[0])->toBeGreaterThanOrEqual($previousTimestamp);
        $previousTimestamp = $point[0];
    }
});

it('handles large datasets efficiently', function () {
    // Seed for reproducibility
    mt_srand(123);

    // Simulate 30 days of data at 5-second intervals (518,400 points)
    // For test purposes, use 10,000 points
    $data = [];
    for ($i = 0; $i < 10000; $i++) {
        $data[] = [$i * 5000, mt_rand(0, 100)];
    }

    $startTime = microtime(true);
    $result = downsampleLTTB($data, 1000);
    $executionTime = microtime(true) - $startTime;

    expect(count($result))->toBe(1000);
    expect($executionTime)->toBeLessThan(1.0); // Should complete in under 1 second
});

it('preserves peaks and valleys in data', function () {
    // Create data with clear peaks and valleys
    $data = [];
    for ($i = 0; $i < 100; $i++) {
        if ($i === 25) {
            $value = 100; // Peak
        } elseif ($i === 75) {
            $value = 0; // Valley
        } else {
            $value = 50;
        }
        $data[] = [$i * 1000, $value];
    }

    $result = downsampleLTTB($data, 20);

    // The peak (100) and valley (0) should be preserved due to LTTB algorithm
    $values = array_column($result, 1);

    expect(in_array(100, $values))->toBeTrue();
    expect(in_array(0, $values))->toBeTrue();
});
