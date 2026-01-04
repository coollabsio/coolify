<?php

it('formats zero bytes correctly', function () {
    expect(formatBytes(0))->toBe('0 B');
});

it('formats null bytes correctly', function () {
    expect(formatBytes(null))->toBe('0 B');
});

it('handles negative bytes safely', function () {
    expect(formatBytes(-1024))->toBe('0 B');
    expect(formatBytes(-100))->toBe('0 B');
});

it('formats bytes correctly', function () {
    expect(formatBytes(512))->toBe('512 B');
    expect(formatBytes(1023))->toBe('1023 B');
});

it('formats kilobytes correctly', function () {
    expect(formatBytes(1024))->toBe('1 KB');
    expect(formatBytes(2048))->toBe('2 KB');
    expect(formatBytes(1536))->toBe('1.5 KB');
});

it('formats megabytes correctly', function () {
    expect(formatBytes(1048576))->toBe('1 MB');
    expect(formatBytes(5242880))->toBe('5 MB');
});

it('formats gigabytes correctly', function () {
    expect(formatBytes(1073741824))->toBe('1 GB');
    expect(formatBytes(2147483648))->toBe('2 GB');
});

it('respects precision parameter', function () {
    expect(formatBytes(1536, 0))->toBe('2 KB');
    expect(formatBytes(1536, 1))->toBe('1.5 KB');
    expect(formatBytes(1536, 2))->toBe('1.5 KB');
    expect(formatBytes(1536, 3))->toBe('1.5 KB');
});
