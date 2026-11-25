<?php

test('formatBytes helper formats file sizes correctly', function () {
    // Test various file sizes
    expect(formatBytes(0))->toBe('0 B');
    expect(formatBytes(null))->toBe('0 B');
    expect(formatBytes(1024))->toBe('1 KB');
    expect(formatBytes(1048576))->toBe('1 MB');
    expect(formatBytes(1073741824))->toBe('1 GB');
    expect(formatBytes(1099511627776))->toBe('1 TB');

    // Test with different sizes
    expect(formatBytes(512))->toBe('512 B');
    expect(formatBytes(2048))->toBe('2 KB');
    expect(formatBytes(5242880))->toBe('5 MB');
    expect(formatBytes(10737418240))->toBe('10 GB');

    // Test precision
    expect(formatBytes(1536, 2))->toBe('1.5 KB');
    expect(formatBytes(1572864, 1))->toBe('1.5 MB');
});
