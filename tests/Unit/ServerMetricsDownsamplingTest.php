<?php

/**
 * LTTB (Largest-Triangle-Three-Buckets) algorithm implementation for testing.
 * This mirrors the implementation in App\Models\Server::downsampleLTTB()
 */
function downsampleLTTB(array $data, int $threshold): array
{
    $dataLength = count($data);

    if ($threshold >= $dataLength || $threshold <= 2) {
        return $data;
    }

    $sampled = [];
    $sampled[] = $data[0]; // Always keep first point

    $bucketSize = ($dataLength - 2) / ($threshold - 2);

    $a = 0; // Index of previous selected point

    for ($i = 0; $i < $threshold - 2; $i++) {
        // Calculate bucket range
        $bucketStart = (int) floor(($i + 1) * $bucketSize) + 1;
        $bucketEnd = (int) floor(($i + 2) * $bucketSize) + 1;
        $bucketEnd = min($bucketEnd, $dataLength - 1);

        // Calculate average point for next bucket (used as reference)
        $nextBucketStart = (int) floor(($i + 2) * $bucketSize) + 1;
        $nextBucketEnd = (int) floor(($i + 3) * $bucketSize) + 1;
        $nextBucketEnd = min($nextBucketEnd, $dataLength - 1);

        $avgX = 0;
        $avgY = 0;
        $nextBucketCount = $nextBucketEnd - $nextBucketStart + 1;

        if ($nextBucketCount > 0) {
            for ($j = $nextBucketStart; $j <= $nextBucketEnd; $j++) {
                $avgX += $data[$j][0];
                $avgY += $data[$j][1];
            }
            $avgX /= $nextBucketCount;
            $avgY /= $nextBucketCount;
        }

        // Find point in current bucket with largest triangle area
        $maxArea = -1;
        $maxAreaIndex = $bucketStart;

        $pointAX = $data[$a][0];
        $pointAY = $data[$a][1];

        for ($j = $bucketStart; $j <= $bucketEnd; $j++) {
            // Triangle area calculation
            $area = abs(
                ($pointAX - $avgX) * ($data[$j][1] - $pointAY) -
                ($pointAX - $data[$j][0]) * ($avgY - $pointAY)
            ) * 0.5;

            if ($area > $maxArea) {
                $maxArea = $area;
                $maxAreaIndex = $j;
            }
        }

        $sampled[] = $data[$maxAreaIndex];
        $a = $maxAreaIndex;
    }

    $sampled[] = $data[$dataLength - 1]; // Always keep last point

    return $sampled;
}

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
    // Generate 100 data points
    $data = [];
    for ($i = 0; $i < 100; $i++) {
        $data[] = [$i * 1000, rand(0, 100) / 10];
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
    // Simulate 30 days of data at 5-second intervals (518,400 points)
    // For test purposes, use 10,000 points
    $data = [];
    for ($i = 0; $i < 10000; $i++) {
        $data[] = [$i * 5000, rand(0, 100)];
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
