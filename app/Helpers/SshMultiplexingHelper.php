<?php

namespace App\Helpers;

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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

        // Connection exists — verify the key hasn't changed since the mux was opened.
        // A cached fingerprint mismatch means the server was re-keyed; the existing
        // mux socket is authenticated with the old key and must be replaced.
        if (self::isKeyStaleForConnection($server)) {
            Log::info('SSH key fingerprint changed since mux was established, refreshing', [
                'server_uuid' => $server->uuid,
            ]);

            return self::refreshMultiplexedConnection($server);
        }

        // Connection exists, ensure we have metadata for age tracking
        if (self::getConnectionAge($server) === null) {
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
     * Validate that the SSH key file exists on disk with correct content and permissions.
     *
     * The previous implementation only checked file existence (`ls`). This caused
     * sporadic "Permission denied (publickey)" errors when:
     * - The key was rotated in the database but the old file remained on disk
     * - A container restart or volume remount left stale key files
     * - File permissions drifted from the required 0600
     *
     * When a mismatch is detected the key is re-written, permissions are enforced,
     * and all multiplexed connections using this key are torn down so the next
     * SSH command authenticates with the current key.
     *
     * @see https://github.com/coollabsio/coolify/issues/7724
     */
    private static function validateSshKey(PrivateKey $privateKey): void
    {
        // Refresh from database to bypass Eloquent's in-memory/relationship cache
        // that may hold a stale key from earlier in the same request lifecycle.
        $freshKey = PrivateKey::find($privateKey->id);
        if (! $freshKey) {
            Log::error('SSH private key no longer exists in database', [
                'key_id' => $privateKey->id,
            ]);

            return;
        }

        $keyLocation = $freshKey->getKeyLocation();
        $needsRewrite = false;

        if (! file_exists($keyLocation)) {
            Log::debug('SSH key file missing from disk, writing key', [
                'key_uuid' => $freshKey->uuid,
            ]);
            $needsRewrite = true;
        } else {
            // Verify file content matches database — detects stale keys after rotation
            $diskContent = file_get_contents($keyLocation);
            if ($diskContent !== $freshKey->private_key) {
                Log::warning('SSH key file content does not match database, refreshing', [
                    'key_uuid' => $freshKey->uuid,
                ]);
                $needsRewrite = true;
            }
        }

        if ($needsRewrite) {
            $freshKey->storeInFileSystem();

            // Invalidate multiplexed connections that authenticated with the old key
            foreach ($freshKey->servers as $server) {
                try {
                    self::removeMuxFile($server);
                    Log::debug('Invalidated mux connection due to SSH key change', [
                        'server_uuid' => $server->uuid,
                        'key_uuid' => $freshKey->uuid,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to invalidate mux connection during key refresh', [
                        'server_uuid' => $server->uuid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Enforce correct permissions — SSH refuses keys that are group/world-readable
        self::ensureKeyPermissions($keyLocation);
    }

    /**
     * Ensure the SSH key file has 0600 permissions.
     *
     * SSH clients reject private keys with overly permissive file modes.
     * Laravel's Storage driver does not guarantee 0600 on every write.
     */
    private static function ensureKeyPermissions(string $keyLocation): void
    {
        if (! file_exists($keyLocation)) {
            return;
        }

        $currentPerms = fileperms($keyLocation) & 0777;
        if ($currentPerms !== 0600) {
            chmod($keyLocation, 0600);
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
     * Check if the SSH key used to establish the mux connection differs from the
     * server's current key. Returns false when no cached fingerprint exists
     * (pre-upgrade connections) to avoid unnecessary churn.
     */
    private static function isKeyStaleForConnection(Server $server): bool
    {
        $cached = Cache::get("ssh_mux_key_fingerprint_{$server->uuid}");
        if ($cached === null) {
            return false;
        }

        $privateKey = PrivateKey::find($server->private_key_id);
        if (! $privateKey) {
            return true; // Key deleted — force refresh to surface a clear error
        }

        return $cached !== $privateKey->fingerprint;
    }

    /**
     * Store connection metadata (timestamp + key fingerprint) when a new
     * connection is established.
     */
    private static function storeConnectionMetadata(Server $server): void
    {
        $ttl = config('constants.ssh.mux_persist_time') + 300;

        Cache::put("ssh_mux_connection_time_{$server->uuid}", time(), $ttl);

        // Record which key authenticated this connection for later staleness checks.
        $privateKey = PrivateKey::find($server->private_key_id);
        if ($privateKey && $privateKey->fingerprint) {
            Cache::put("ssh_mux_key_fingerprint_{$server->uuid}", $privateKey->fingerprint, $ttl);
        }
    }

    /**
     * Clear connection metadata from cache
     */
    private static function clearConnectionMetadata(Server $server): void
    {
        Cache::forget("ssh_mux_connection_time_{$server->uuid}");
        Cache::forget("ssh_mux_key_fingerprint_{$server->uuid}");
    }
}
