<?php

use App\Models\S3Storage;

test('S3Storage model has correct cast definitions', function () {
    $s3Storage = new S3Storage;
    $casts = $s3Storage->getCasts();

    expect($casts['is_usable'])->toBe('boolean');
    expect($casts['key'])->toBe('encrypted');
    expect($casts['secret'])->toBe('encrypted');
});

test('S3Storage isUsable method returns is_usable attribute value', function () {
    $s3Storage = new S3Storage;

    // Set the attribute directly to avoid encryption
    $s3Storage->setRawAttributes(['is_usable' => true]);
    expect($s3Storage->isUsable())->toBeTrue();

    $s3Storage->setRawAttributes(['is_usable' => false]);
    expect($s3Storage->isUsable())->toBeFalse();

    $s3Storage->setRawAttributes(['is_usable' => null]);
    expect($s3Storage->isUsable())->toBeNull();
});

test('S3Storage awsUrl method constructs correct URL format', function () {
    $s3Storage = new S3Storage;

    // Set attributes without triggering encryption
    $s3Storage->setRawAttributes([
        'endpoint' => 'https://s3.amazonaws.com',
        'bucket' => 'test-bucket',
    ]);

    expect($s3Storage->awsUrl())->toBe('https://s3.amazonaws.com/test-bucket');

    // Test with custom endpoint
    $s3Storage->setRawAttributes([
        'endpoint' => 'https://minio.example.com:9000',
        'bucket' => 'backups',
    ]);

    expect($s3Storage->awsUrl())->toBe('https://minio.example.com:9000/backups');
});

test('S3Storage model is guarded correctly', function () {
    $s3Storage = new S3Storage;

    // The model should have $guarded = [] which means everything is fillable
    expect($s3Storage->getGuarded())->toBe([]);
});

test('S3Storage path attribute normalizes path correctly', function () {
    $s3Storage = new S3Storage;

    // Path should be normalized to start with /
    $s3Storage->path = 'backups/coolify';
    expect($s3Storage->path)->toBe('/backups/coolify');

    // Path with leading slash should remain unchanged
    $s3Storage->path = '/backups/coolify';
    expect($s3Storage->path)->toBe('/backups/coolify');

    // Empty path should return null
    $s3Storage->path = '';
    expect($s3Storage->path)->toBeNull();

    // Null path should return null
    $s3Storage->path = null;
    expect($s3Storage->path)->toBeNull();

    // Path with whitespace should be trimmed
    $s3Storage->path = '  backups/coolify  ';
    expect($s3Storage->path)->toBe('/backups/coolify');
});

test('S3Storage path attribute handles various path formats', function () {
    $s3Storage = new S3Storage;

    // Simple path
    $s3Storage->path = 'instance-1';
    expect($s3Storage->path)->toBe('/instance-1');

    // Nested path
    $s3Storage->path = 'production/backups/db';
    expect($s3Storage->path)->toBe('/production/backups/db');

    // Path with special characters
    $s3Storage->path = 'my-instance_2024.backups';
    expect($s3Storage->path)->toBe('/my-instance_2024.backups');
});

test('S3Storage path attribute handles edge cases', function () {
    $s3Storage = new S3Storage;

    // Multiple consecutive slashes are preserved (validation should catch this)
    $s3Storage->path = 'path//to///backup';
    expect($s3Storage->path)->toBe('/path//to///backup');

    // Path ending with slash - trailing slashes are now stripped
    $s3Storage->path = 'backups/coolify/';
    expect($s3Storage->path)->toBe('/backups/coolify');

    // Only whitespace should return null
    $s3Storage->path = '   ';
    expect($s3Storage->path)->toBeNull();

    // Path with dots (valid single dots)
    $s3Storage->path = 'path.with.dots';
    expect($s3Storage->path)->toBe('/path.with.dots');

    // Only slashes should return null
    $s3Storage->path = '///';
    expect($s3Storage->path)->toBeNull();
});
