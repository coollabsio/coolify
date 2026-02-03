<?php

namespace App\Helpers;

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class SshMultiplexingHelper
{
    public static function serverSshConfiguration(Server $server)
    {
        $privateKey = PrivateKey::findOrFail($server->private_key_id);
        $sshKeyLocation = $privateKey->getKeyLocation();
        $muxFilename = '/var/www/html/storage/app/ssh/mux/mux_'.$server->uuid;

        return [
            'sshKeyLocation' => $sshKeyLocation,
            'muxFilename' => $muxFilename,
        ];
    }

    public static function ensureMultiplexedConnection(Server $server): bool
    {
        if (! self::isMultiplexingEnabled()) {
            return false;
        }

        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];

        // Check if connection exists
        $checkCommand = "ssh -O check -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $checkCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $checkCommand .= "{$server->user}@{$server->ip}";
        $process = Process::run($checkCommand);

        if ($process->exitCode() !== 0) {
            return self::establishNewMultiplexedConnection($server);
        }

        // Connection exists - check if the SSH key has changed since connection was established
        // This prevents using a multiplexed connection that was made with a different key
        // Fixes: https://github.com/coollabsio/coolify/issues/7724
        if (self::isKeyMismatch($server)) {
            Log::warning('SSH key mismatch detected for multiplexed connection, refreshing', [
                'server' => $server->name ?? $server->uuid,
                'server_uuid' => $server->uuid,
            ]);

            return self::refreshMultiplexedConnection($server);
        }

        // Connection exists, ensure we have metadata for age tracking
        if (self::getConnectionAge($server) === null) {
            // Existing connection but no metadata, store current time as fallback
            self::storeConnectionMetadata($server);
        }

        // Connection exists, check if it needs refresh due to age
        if (self::isConnectionExpired($server)) {
            return self::refreshMultiplexedConnection($server);
        }

        // Perform health check if enabled
        if (config('constants.ssh.mux_health_check_enabled')) {
            if (! self::isConnectionHealthy($server)) {
                return self::refreshMultiplexedConnection($server);
            }
        }

        return true;
    }

    public static function establishNewMultiplexedConnection(Server $server): bool
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];
        $connectionTimeout = config('constants.ssh.connection_timeout');
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $establishCommand = "ssh -fNM -o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $establishCommand .= ' -o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $establishCommand .= self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval);
        $establishCommand .= "{$server->user}@{$server->ip}";
        $establishProcess = Process::run($establishCommand);
        if ($establishProcess->exitCode() !== 0) {
            return false;
        }

        // Store connection metadata for tracking
        self::storeConnectionMetadata($server);

        return true;
    }

    public static function removeMuxFile(Server $server)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];

        $closeCommand = "ssh -O exit -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $closeCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $closeCommand .= "{$server->user}@{$server->ip}";
        Process::run($closeCommand);

        // Clear connection metadata from cache
        self::clearConnectionMetadata($server);
    }

    public static function generateScpCommand(Server $server, string $source, string $dest)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $scp_command = "timeout $timeout scp ";
        if ($server->isIpv6()) {
            $scp_command .= '-6 ';
        }
        if (self::isMultiplexingEnabled()) {
            try {
                if (self::ensureMultiplexedConnection($server)) {
                    $scp_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
                }
            } catch (\Exception $e) {
                Log::warning('SSH multiplexing failed for SCP, falling back to non-multiplexed connection', [
                    'server' => $server->name ?? $server->ip,
                    'error' => $e->getMessage(),
                ]);
                // Continue without multiplexing
            }
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $scp_command .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $scp_command .= self::getCommonSshOptions($server, $sshKeyLocation, config('constants.ssh.connection_timeout'), config('constants.ssh.server_interval'), isScp: true);
        if ($server->isIpv6()) {
            $scp_command .= "{$source} {$server->user}@[{$server->ip}]:{$dest}";
        } else {
            $scp_command .= "{$source} {$server->user}@{$server->ip}:{$dest}";
        }

        return $scp_command;
    }

    public static function generateSshCommand(Server $server, string $command, bool $disableMultiplexing = false)
    {
        if ($server->settings->force_disabled) {
            throw new \RuntimeException('Server is disabled.');
        }

        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];

        self::validateSshKey($server->privateKey);

        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $ssh_command = "timeout $timeout ssh ";

        $multiplexingSuccessful = false;
        if (! $disableMultiplexing && self::isMultiplexingEnabled()) {
            try {
                $multiplexingSuccessful = self::ensureMultiplexedConnection($server);
                if ($multiplexingSuccessful) {
                    $ssh_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
                }
            } catch (\Exception $e) {
                // Continue without multiplexing
            }
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $ssh_command .= "-o ProxyCommand='cloudflared access ssh --hostname %h' ";
        }

        $ssh_command .= self::getCommonSshOptions($server, $sshKeyLocation, config('constants.ssh.connection_timeout'), config('constants.ssh.server_interval'));

        $delimiter = Hash::make($command);
        $delimiter = base64_encode($delimiter);
        $command = str_replace($delimiter, '', $command);

        $ssh_command .= "{$server->user}@{$server->ip} 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;

        return $ssh_command;
    }

    private static function isMultiplexingEnabled(): bool
    {
        return config('constants.ssh.mux_enabled') && ! config('constants.coolify.is_windows_docker_desktop');
    }

    /**
     * Validate that the SSH key file exists and contains the correct content.
     *
     * This method addresses sporadic "Permission denied (publickey)" errors that occur when:
     * 1. SSH key file content becomes stale/mismatched with the database
     * 2. Key files don't exist on disk
     * 3. Key content changes but file isn't updated
     *
     * Uses Laravel's Storage disk for secure file operations instead of shell commands.
     *
     * @see https://github.com/coollabsio/coolify/issues/7724
     */
    private static function validateSshKey(PrivateKey $privateKey): void
    {
        // Refresh from database to bypass Eloquent's in-memory caching
        // This ensures we always have the latest key content, even if the model
        // was loaded earlier in the request lifecycle and the key was changed
        $freshKey = PrivateKey::find($privateKey->id);
        if (! $freshKey) {
            Log::error('SSH key not found in database during validation', [
                'key_id' => $privateKey->id,
            ]);

            return;
        }

        $disk = Storage::disk('ssh-keys');
        $filename = "ssh_key@{$freshKey->uuid}";

        // Check if the key file exists
        if (! $disk->exists($filename)) {
            Log::debug('SSH key file not found, creating it', [
                'key_uuid' => $freshKey->uuid,
            ]);
            $freshKey->storeInFileSystem();

            return;
        }

        // File exists - verify content matches the database
        // This prevents using stale/wrong keys when the key was updated
        $storedContent = $disk->get($filename);
        $expectedContent = $freshKey->private_key;

        if ($storedContent !== $expectedContent) {
            Log::warning('SSH key file content mismatch detected, refreshing key file', [
                'key_uuid' => $freshKey->uuid,
                'stored_fingerprint' => substr(md5($storedContent ?? ''), 0, 8),
                'expected_fingerprint' => substr(md5($expectedContent ?? ''), 0, 8),
            ]);

            $freshKey->storeInFileSystem();

            // Invalidate all multiplexed connections using this key
            // to prevent using cached connections with the old key
            self::invalidateConnectionsForKey($freshKey);
        }
    }

    /**
     * Invalidate all multiplexed connections for servers using a specific SSH key.
     * This should be called when the key content changes to prevent using stale connections.
     */
    private static function invalidateConnectionsForKey(PrivateKey $privateKey): void
    {
        foreach ($privateKey->servers as $server) {
            try {
                self::removeMuxFile($server);
                Log::debug('Invalidated mux connection due to key content change', [
                    'server_uuid' => $server->uuid,
                    'key_uuid' => $privateKey->uuid,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to invalidate mux connection during key validation', [
                    'server_uuid' => $server->uuid,
                    'key_uuid' => $privateKey->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private static function getCommonSshOptions(Server $server, string $sshKeyLocation, int $connectionTimeout, int $serverInterval, bool $isScp = false): string
    {
        $options = "-i {$sshKeyLocation} "
            .'-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            .'-o PasswordAuthentication=no '
            ."-o ConnectTimeout=$connectionTimeout "
            ."-o ServerAliveInterval=$serverInterval "
            .'-o RequestTTY=no '
            .'-o LogLevel=ERROR ';

        // Bruh
        if ($isScp) {
            $options .= "-P {$server->port} ";
        } else {
            $options .= "-p {$server->port} ";
        }

        return $options;
    }

    /**
     * Check if the multiplexed connection is healthy by running a test command
     */
    public static function isConnectionHealthy(Server $server): bool
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];
        $healthCheckTimeout = config('constants.ssh.mux_health_check_timeout');

        $healthCommand = "timeout $healthCheckTimeout ssh -o ControlMaster=auto -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $healthCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $healthCommand .= "{$server->user}@{$server->ip} 'echo \"health_check_ok\"'";

        $process = Process::run($healthCommand);
        $isHealthy = $process->exitCode() === 0 && str_contains($process->output(), 'health_check_ok');

        return $isHealthy;
    }

    /**
     * Check if the connection has exceeded its maximum age
     */
    public static function isConnectionExpired(Server $server): bool
    {
        $connectionAge = self::getConnectionAge($server);
        $maxAge = config('constants.ssh.mux_max_age');

        return $connectionAge !== null && $connectionAge > $maxAge;
    }

    /**
     * Check if the server's current SSH key is different from the one used to establish the multiplexed connection.
     *
     * This detects when a user has changed the server's SSH key and the old connection should not be reused.
     * Fixes sporadic "Permission denied" errors when key changes aren't reflected in existing connections.
     *
     * @see https://github.com/coollabsio/coolify/issues/7724
     */
    public static function isKeyMismatch(Server $server): bool
    {
        $fingerprintCacheKey = "ssh_mux_key_fingerprint_{$server->uuid}";
        $cachedFingerprint = Cache::get($fingerprintCacheKey);

        // If no cached fingerprint, we can't verify - connection metadata missing
        // This can happen for connections established before this fix was deployed
        if ($cachedFingerprint === null) {
            return false;
        }

        // Get the current key from the database (fresh, not from relationship cache)
        $privateKey = PrivateKey::find($server->private_key_id);
        if (! $privateKey) {
            Log::warning('Server has no valid private key during mux check', [
                'server_uuid' => $server->uuid,
                'private_key_id' => $server->private_key_id,
            ]);

            return true; // Force refresh to get proper error
        }

        $currentFingerprint = $privateKey->fingerprint;

        // Compare fingerprints
        if ($cachedFingerprint !== $currentFingerprint) {
            Log::info('SSH key fingerprint mismatch detected', [
                'server_uuid' => $server->uuid,
                'cached_fingerprint' => substr($cachedFingerprint ?? '', 0, 16).'...',
                'current_fingerprint' => substr($currentFingerprint ?? '', 0, 16).'...',
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get the age of the current connection in seconds
     */
    public static function getConnectionAge(Server $server): ?int
    {
        $cacheKey = "ssh_mux_connection_time_{$server->uuid}";
        $connectionTime = Cache::get($cacheKey);

        if ($connectionTime === null) {
            return null;
        }

        return time() - $connectionTime;
    }

    /**
     * Refresh a multiplexed connection by closing and re-establishing it
     */
    public static function refreshMultiplexedConnection(Server $server): bool
    {
        // Close existing connection
        self::removeMuxFile($server);

        // Establish new connection
        return self::establishNewMultiplexedConnection($server);
    }

    /**
     * Store connection metadata when a new connection is established.
     * Includes connection time and the SSH key fingerprint used for the connection.
     */
    private static function storeConnectionMetadata(Server $server): void
    {
        $cacheKey = "ssh_mux_connection_time_{$server->uuid}";
        $cacheTtl = config('constants.ssh.mux_persist_time') + 300; // Cache slightly longer than persist time
        Cache::put($cacheKey, time(), $cacheTtl);

        // Store the key fingerprint used for this connection
        // This allows us to detect when a different key should be used
        $privateKey = PrivateKey::find($server->private_key_id);
        if ($privateKey && $privateKey->fingerprint) {
            $fingerprintCacheKey = "ssh_mux_key_fingerprint_{$server->uuid}";
            Cache::put($fingerprintCacheKey, $privateKey->fingerprint, $cacheTtl);
        }
    }

    /**
     * Clear connection metadata from cache (time and key fingerprint)
     */
    private static function clearConnectionMetadata(Server $server): void
    {
        $cacheKey = "ssh_mux_connection_time_{$server->uuid}";
        Cache::forget($cacheKey);

        // Also clear the key fingerprint cache
        $fingerprintCacheKey = "ssh_mux_key_fingerprint_{$server->uuid}";
        Cache::forget($fingerprintCacheKey);
    }
}
