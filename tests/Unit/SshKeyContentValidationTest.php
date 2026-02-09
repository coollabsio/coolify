<?php

namespace Tests\Unit;

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use App\Models\Server;
use Tests\TestCase;

/**
 * Unit tests for the SSH key content validation and mux invalidation fix.
 *
 * These tests verify the structural changes made to SshMultiplexingHelper and
 * Server to address sporadic "Permission denied (publickey)" errors caused by
 * stale SSH key files and stale multiplexed connections.
 *
 * @see https://github.com/coollabsio/coolify/issues/7724
 */
class SshKeyContentValidationTest extends TestCase
{
    /**
     * Verify validateSshKey uses the Storage disk instead of shell `ls`.
     */
    public function test_validate_ssh_key_uses_storage_disk(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString("Storage::disk('ssh-keys')", $source);
        $this->assertStringContainsString('$disk->exists($filename)', $source);
        $this->assertStringContainsString('$disk->get($filename)', $source);
    }

    /**
     * Verify that validateSshKey compares file content against the database.
     */
    public function test_validate_ssh_key_compares_file_content_with_database(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString(
            '$storedContent !== $freshKey->private_key',
            $source
        );
    }

    /**
     * Verify that validateSshKey refreshes the PrivateKey from the database to
     * bypass Eloquent's in-memory cache.
     */
    public function test_validate_ssh_key_refreshes_key_from_database(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString('$freshKey = PrivateKey::find($privateKey->id)', $source);
    }

    /**
     * Verify that the helper invalidates mux connections when key content changes.
     */
    public function test_validate_ssh_key_invalidates_mux_on_content_mismatch(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString('self::invalidateConnectionsForKey($freshKey)', $source);
    }

    /**
     * Verify the invalidateConnectionsForKey method exists and is private static.
     */
    public function test_invalidate_connections_for_key_method_exists(): void
    {
        $method = new \ReflectionMethod(SshMultiplexingHelper::class, 'invalidateConnectionsForKey');

        $this->assertTrue($method->isPrivate());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Verify that invalidateConnectionsForKey iterates through servers using the key.
     */
    public function test_invalidate_connections_for_key_removes_mux_for_all_servers(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString('foreach ($privateKey->servers as $server)', $source);
        $this->assertStringContainsString('self::removeMuxFile($server)', $source);
    }

    /**
     * Verify the isKeyMismatch method exists and is public static.
     */
    public function test_is_key_mismatch_method_exists(): void
    {
        $method = new \ReflectionMethod(SshMultiplexingHelper::class, 'isKeyMismatch');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Verify that isKeyMismatch compares cached fingerprint with current key fingerprint.
     */
    public function test_is_key_mismatch_compares_fingerprints(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString('ssh_mux_key_fingerprint_', $source);
        $this->assertStringContainsString('$cachedFingerprint !== $privateKey->fingerprint', $source);
    }

    /**
     * Verify that ensureMultiplexedConnection checks for key mismatch.
     */
    public function test_ensure_multiplexed_connection_checks_key_mismatch(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString('self::isKeyMismatch($server)', $source);
    }

    /**
     * Verify that storeConnectionMetadata persists the key fingerprint.
     */
    public function test_store_connection_metadata_includes_key_fingerprint(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString(
            'Cache::put($fingerprintCacheKey, $privateKey->fingerprint',
            $source
        );
    }

    /**
     * Verify that clearConnectionMetadata also clears the fingerprint cache.
     */
    public function test_clear_connection_metadata_clears_fingerprint(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        // Must clear both connection time and fingerprint
        $this->assertStringContainsString('Cache::forget("ssh_mux_connection_time_{$server->uuid}")', $source);
        $this->assertStringContainsString('Cache::forget("ssh_mux_key_fingerprint_{$server->uuid}")', $source);
    }

    /**
     * Verify that the Server model invalidates mux when private_key_id changes.
     */
    public function test_server_model_invalidates_mux_on_key_id_change(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(Server::class))->getFileName()
        );

        $this->assertStringContainsString("wasChanged('private_key_id')", $source);
        $this->assertStringContainsString('SshMultiplexingHelper::removeMuxFile($server)', $source);
    }

    /**
     * Verify that the Server model imports SshMultiplexingHelper.
     */
    public function test_server_model_imports_ssh_multiplexing_helper(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(Server::class))->getFileName()
        );

        $this->assertStringContainsString('use App\Helpers\SshMultiplexingHelper;', $source);
    }

    /**
     * Verify that validateSshKey logs warnings on content mismatch.
     */
    public function test_validate_ssh_key_logs_on_mismatch(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SshMultiplexingHelper::class))->getFileName()
        );

        $this->assertStringContainsString(
            "Log::warning('SSH key file content does not match database",
            $source
        );
        $this->assertStringContainsString(
            "Log::debug('SSH key file missing from disk, storing key",
            $source
        );
    }
}
