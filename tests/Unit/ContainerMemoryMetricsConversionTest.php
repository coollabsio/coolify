<?php

/**
 * Sentinel reports container memory `used` in bytes. The application metrics
 * chart labels those values as megabytes, so the series must be converted.
 *
 * @see https://github.com/coollabsio/coolify/issues/11246
 */
it('converts sentinel container memory samples from bytes to megabytes', function () {
    $metrics = [
        [1_700_000_000_000, 84_996_096.0],
        [1_700_000_005_000, 104_857_600.0],
    ];

    $converted = convertContainerMemoryBytesToMegabytes($metrics);

    expect($converted)->toBe([
        [1_700_000_000_000, 81.06],
        [1_700_000_005_000, 100.0],
    ]);
});

it('preserves timestamps and converts a zero byte sample to zero megabytes', function () {
    expect(convertContainerMemoryBytesToMegabytes([
        [1_700_000_000_000, 0.0],
    ]))->toBe([
        [1_700_000_000_000, 0.0],
    ]);
});

it('leaves an empty series unchanged', function () {
    expect(convertContainerMemoryBytesToMegabytes([]))->toBe([]);
});
