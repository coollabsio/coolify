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
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $attempt++;
                
                // Ensure SSH key is valid before attempting connection
                self::validateSshKey($server->privateKey);

                // Check if connection exists
                $checkCommand = "ssh -O check -o ControlPath=$muxSocket ";
                if (data_get($server, 'settings.is_cloudflare_tunnel')) {
                    $checkCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
                }
                $checkCommand .= "{$server->user}@{$server->ip}";
                $process = Process::run($checkCommand);

                if ($process->exitCode() === 0) {
                    // Connection exists and is healthy
                    
                    // Ensure we have metadata for age tracking
                    if (self::getConnectionAge($server) === null) {
                        self::storeConnectionMetadata($server);
                    }

                    // Check if connection needs refresh due to age
                    if (self::isConnectionExpired($server)) {
                        return self::refreshMultiplexedConnection($server);
                    }

                    // Perform health check if enabled
                    if (config('constants.ssh.mux_health_check_enabled')) {
                        if (! self::isConnectionHealthy($server)) {
                            // Connection unhealthy, try to refresh
                            if ($attempt < $maxRetries) {
                                self::removeMuxFile($server);
                                sleep(1);
                                continue;
                            }
                            return false;
                        }
                    }

                    return true;
                }

                // Connection doesn't exist, try to establish
                if (self::establishNewMultiplexedConnection($server)) {
                    return true;
                }

                // If establishment failed, retry with cleanup
                if ($attempt < $maxRetries) {
                    Log::warning('SSH multiplexing attempt '.$attempt.' failed, cleaning up and retrying', [
                        'server' => $server->name ?? $server->ip,
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries
                    ]);
                    self::removeMuxFile($server);
                    sleep(1);
                }
            } catch (\Exception $e) {
                Log::warning('SSH multiplexing exception', [
                    'server' => $server->name ?? $server->ip,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                if ($attempt < $maxRetries) {
                    self::removeMuxFile($server);
                    sleep(1);
                }
            }
        }

        // All retries exhausted, log final failure
        Log::error('SSH multiplexing failed after '.$maxRetries.' attempts', [
            'server' => $server->name ?? $server->ip
        ]);
        
        return false;
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

    private static function validateSshKey(PrivateKey $privateKey): void
    {
        $keyLocation = $privateKey->getKeyLocation();
        
        // Check if key file exists
        if (!file_exists($keyLocation)) {
            Log::warning('SSH key file not found, regenerating', ['key_path' => $keyLocation]);
            $privateKey->storeInFileSystem();
            return;
        }
        
        // Validate file permissions (SSH requires 0600)
        $perms = substr(sprintf('%o', fileperms($keyLocation)), -4);
        if ($perms !== '0600') {
            Log::warning('SSH key has incorrect permissions, fixing', [
                'key_path' => $keyLocation,
                'current_perms' => $perms,
                'expected_perms' => '0600'
            ]);
            chmod($keyLocation, 0600);
        }
        
        // Validate key content is valid
        $keyContent = file_get_contents($keyLocation);
        if (empty($keyContent)) {
            Log::warning('SSH key file is empty, regenerating', ['key_path' => $keyLocation]);
            $privateKey->storeInFileSystem();
            return;
        }
        
        // Check for valid PEM format
        if (!preg_match('/BEGIN.*PRIVATE KEY/', $keyContent)) {
            Log::error('SSH key file is malformed, regenerating', ['key_path' => $keyLocation]);
            $privateKey->storeInFileSystem();
            return;
        }
        
        // Test key accessibility
        $checkKeyCommand = "ssh-keygen -y -f $keyLocation >/dev/null 2>&1";
        $keyCheckProcess = Process::run($checkKeyCommand);
        
        if ($keyCheckProcess->exitCode() !== 0) {
            Log::error('SSH key validation failed, regenerating', [
                'key_path' => $keyLocation,
                'error' => $keyCheckProcess->errorOutput()
            ]);
            $privateKey->storeInFileSystem();
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

        if (!$isHealthy) {
            Log::warning('SSH connection health check failed', [
                'server' => $server->name ?? $server->ip,
                'error' => $process->errorOutput(),
                'exit_code' => $process->exitCode()
            ]);
            
            // Attempt to recover the connection
            self::removeMuxFile($server);
            sleep(1);
            
            // Try to re-establish
            if (self::establishNewMultiplexedConnection($server)) {
                Log::info('SSH connection recovered after health check failure', [
                    'server' => $server->name ?? $server->ip
                ]);
                return true;
            }
            
            return false;
        }

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
     * Store connection metadata when a new connection is established
     */
    private static function storeConnectionMetadata(Server $server): void
    {
        $cacheKey = "ssh_mux_connection_time_{$server->uuid}";
        Cache::put($cacheKey, time(), config('constants.ssh.mux_persist_time') + 300); // Cache slightly longer than persist time
    }

    /**
     * Clear connection metadata from cache
     */
    private static function clearConnectionMetadata(Server $server): void
    {
        $cacheKey = "ssh_mux_connection_time_{$server->uuid}";
        Cache::forget($cacheKey);
    }
}
