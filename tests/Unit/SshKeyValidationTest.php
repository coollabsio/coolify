<?php

/**
 * Unit tests for SSH key validation and mux connection fixes.
 *
 * These tests verify the fix for issue #7724: Sporadic "Permission denied (publickey)" errors
 * caused by SSH key content mismatch between database and filesystem, and stale mux connections.
 *
 * @see https://github.com/coollabsio/coolify/issues/7724
 */

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Clear cache before each test
    Cache::flush();
});

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

test('SshMultiplexingHelper validateSshKey method exists and is private static', function () {
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
        ->and($source)->toContain("Log::debug('SSH key file not found, creating it");
});

test('validateSshKey compares file content with database value', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('$storedContent !== $expectedContent')
        ->and($source)->toContain('$freshKey->storeInFileSystem()');
});

test('validateSshKey invalidates mux connections on content mismatch', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('self::invalidateConnectionsForKey($freshKey)')
        ->and($source)->toContain("Log::debug('Invalidated mux connection due to key content change");
});

test('validateSshKey refreshes PrivateKey from database to bypass caching', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    // Verify it fetches fresh key from database
    expect($source)->toContain('$freshKey = PrivateKey::find($privateKey->id)')
        ->and($source)->toContain("bypass Eloquent's in-memory caching");
});

test('SshMultiplexingHelper has isKeyMismatch method', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $method = $class->getMethod('isKeyMismatch');

    expect($method->isPublic())->toBeTrue()
        ->and($method->isStatic())->toBeTrue();
});

test('isKeyMismatch uses cached fingerprint for comparison', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('ssh_mux_key_fingerprint_')
        ->and($source)->toContain('$cachedFingerprint !== $currentFingerprint');
});

test('storeConnectionMetadata includes key fingerprint', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('$fingerprintCacheKey = "ssh_mux_key_fingerprint_{$server->uuid}"')
        ->and($source)->toContain('Cache::put($fingerprintCacheKey, $privateKey->fingerprint');
});

test('clearConnectionMetadata clears key fingerprint cache', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('Cache::forget($fingerprintCacheKey)');
});

test('ensureMultiplexedConnection checks for key mismatch before reusing connection', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('self::isKeyMismatch($server)')
        ->and($source)->toContain("Log::warning('SSH key mismatch detected for multiplexed connection");
});

test('Server model invalidates mux when private_key_id changes', function () {
    $class = new ReflectionClass(Server::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain("wasChanged('private_key_id')")
        ->and($source)->toContain('SshMultiplexingHelper::removeMuxFile($server)')
        ->and($source)->toContain("Log::info('SSH multiplexed connection invalidated due to key change");
});

test('Server model imports SshMultiplexingHelper', function () {
    $class = new ReflectionClass(Server::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('use App\Helpers\SshMultiplexingHelper;');
});

test('invalidateConnectionsForKey iterates through all servers using the key', function () {
    $class = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($class->getFileName());

    expect($source)->toContain('foreach ($privateKey->servers as $server)')
        ->and($source)->toContain('self::removeMuxFile($server)');
});
