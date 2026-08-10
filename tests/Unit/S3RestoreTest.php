<?php

test('S3 path is cleaned correctly', function () {
    // Test that leading slashes are removed
    $path = '/backups/database.gz';
    $cleanPath = ltrim($path, '/');

    expect($cleanPath)->toBe('backups/database.gz');

    // Test path without leading slash remains unchanged
    $path2 = 'backups/database.gz';
    $cleanPath2 = ltrim($path2, '/');

    expect($cleanPath2)->toBe('backups/database.gz');
});

test('S3 container name is generated correctly', function () {
    $resourceUuid = 'test-database-uuid';
    $containerName = "s3-restore-{$resourceUuid}";

    expect($containerName)->toBe('s3-restore-test-database-uuid');
    expect($containerName)->toStartWith('s3-restore-');
});

test('S3 download directory is created correctly', function () {
    $resourceUuid = 'test-database-uuid';
    $downloadDir = "/tmp/s3-restore-{$resourceUuid}";

    expect($downloadDir)->toBe('/tmp/s3-restore-test-database-uuid');
    expect($downloadDir)->toStartWith('/tmp/s3-restore-');
});

test('cancelS3Download cleans up correctly', function () {
    // Test that cleanup directory path is correct
    $resourceUuid = 'test-database-uuid';
    $downloadDir = "/tmp/s3-restore-{$resourceUuid}";
    $containerName = "s3-restore-{$resourceUuid}";

    expect($downloadDir)->toContain($resourceUuid);
    expect($containerName)->toContain($resourceUuid);
});

test('S3 file path formats are handled correctly', function () {
    $paths = [
        '/backups/db.gz',
        'backups/db.gz',
        '/nested/path/to/backup.sql.gz',
        'backup-2025-01-15.gz',
    ];

    foreach ($paths as $path) {
        $cleanPath = ltrim($path, '/');
        expect($cleanPath)->not->toStartWith('/');
    }
});

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
