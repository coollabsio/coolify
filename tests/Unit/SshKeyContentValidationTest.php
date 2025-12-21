<?php

/**
 * Unit tests for SSH key content validation fix.
 *
 * These tests verify the fix for issue #7724: Sporadic "Permission denied (publickey)" errors
 * caused by SSH key content mismatch between database and filesystem.
 *
 * @see https://github.com/coollabsio/coolify/issues/7724
 */

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;

test('PrivateKey model encrypts private_key attribute', function () {
    $privateKey = new PrivateKey;
    $casts = $privateKey->getCasts();

    expect($casts['private_key'])->toBe('encrypted');
});

test('PrivateKey getKeyLocation returns correct path format', function () {
    $privateKey = new PrivateKey;
    $privateKey->uuid = 'test-uuid-123';

    expect($privateKey->getKeyLocation())->toBe('/var/www/html/storage/app/ssh/keys/ssh_key@test-uuid-123');
});

test('SshMultiplexingHelper validateSshKey method exists', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $method = $class->getMethod('validateSshKey');

    expect($method->isPrivate())->toBeTrue()
        ->and($method->isStatic())->toBeTrue();
});

test('SshMultiplexingHelper uses Storage disk for key validation', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain("Storage::disk('ssh-keys')")
        ->and($source)->toContain('$disk->exists($filename)')
        ->and($source)->toContain('$disk->get($filename)');
});

test('SshMultiplexingHelper logs key content mismatch', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain("Log::warning('SSH key file content mismatch detected")
        ->and($source)->toContain("Log::debug('SSH key file not found, storing key");
});

test('validateSshKey compares file content with database value', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('$storedContent !== $privateKey->private_key')
        ->and($source)->toContain('$privateKey->storeInFileSystem()');
});

test('validateSshKey invalidates mux on content mismatch', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('self::removeMuxFile($server)')
        ->and($source)->toContain("Log::debug('Invalidated mux connection due to key content mismatch");
});
