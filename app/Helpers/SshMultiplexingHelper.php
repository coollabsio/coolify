<?php

namespace App\Helpers;

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class SshMultiplexingHelper
{
    public static function serverSshConfiguration(Server $server): array
    {
        $privateKey = PrivateKey::findOrFail($server->private_key_id);

        return [
            'sshKeyLocation' => $privateKey->getKeyLocation(),
            'muxFilename' => self::muxSocket($server),
        ];
    }

    public static function ensureMultiplexedConnection(Server $server): bool
    {
        if (! self::isMultiplexingEnabled()) {
            return false;
        }

        if (self::connectionIsReusable($server)) {
            return true;
        }

        try {
            return Cache::lock(
                self::connectionLockKey($server),
                config('constants.ssh.mux_lock_ttl')
            )->block(config('constants.ssh.mux_lock_timeout'), function () use ($server) {
                if (self::connectionIsReusable($server)) {
                    return true;
                }

                if (self::masterConnectionExists($server)) {
                    return self::refreshMultiplexedConnection($server);
                }

                return self::establishNewMultiplexedConnection($server);
            });
        } catch (LockTimeoutException) {
            Log::warning('SSH multiplexing lock timeout, falling back to non-multiplexed connection', [
                'server' => $server->name ?? $server->ip,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('SSH multiplexing lock unavailable, falling back to non-multiplexed connection', [
                'server' => $server->name ?? $server->ip,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function establishNewMultiplexedConnection(Server $server): bool
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];
        $connectionTimeout = self::getConnectionTimeout($server);
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $establishCommand = "ssh -fN -o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $establishCommand .= ' -o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $establishCommand .= self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval);
        $establishCommand .= self::escapedUserAtHost($server);

        $establishProcess = Process::run($establishCommand);
        if ($establishProcess->exitCode() !== 0) {
            return false;
        }

        return true;
    }

    public static function removeMuxFile(Server $server): void
    {
        $checkProcess = Process::run(self::muxControlCommand($server, 'check'));
        $pid = preg_match('/pid=(\d+)/', $checkProcess->output().$checkProcess->errorOutput(), $matches)
            ? $matches[1]
            : null;

        if ($pid !== null) {
            self::markMuxProcessAsRetiring($pid, self::muxSocket($server));
        }

        $stopProcess = Process::run(self::muxControlCommand($server, 'stop'));

        if ($pid !== null && ! $stopProcess->successful()) {
            self::unmarkMuxProcessAsRetiring($pid, self::muxSocket($server));
        }
    }

    public static function markMuxProcessAsRetiring(string $pid, string $muxSocket, ?string $processStartTime = null): void
    {
        $processStartTime ??= self::processStartTime($pid);
        Cache::forever(self::muxProcessRetirementKey($pid, $muxSocket, $processStartTime), true);
    }

    public static function isMuxProcessRetiring(string $pid, string $muxSocket, ?string $processStartTime = null): bool
    {
        $processStartTime ??= self::processStartTime($pid);
        $key = self::muxProcessRetirementKey($pid, $muxSocket, $processStartTime);
        if (! Cache::has($key)) {
            return false;
        }

        return true;
    }

    public static function unmarkMuxProcessAsRetiring(string $pid, string $muxSocket, ?string $processStartTime = null): void
    {
        $processStartTime ??= self::processStartTime($pid);
        Cache::forget(self::muxProcessRetirementKey($pid, $muxSocket, $processStartTime));
    }

    public static function generateScpCommand(Server $server, string $source, string $dest): string
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $scpCommand = 'timeout '.config('constants.ssh.command_timeout').' scp ';

        if ($server->isIpv6()) {
            $scpCommand .= '-6 ';
        }

        if (self::isMultiplexingEnabled()) {
            try {
                if (self::ensureMultiplexedConnection($server)) {
                    $scpCommand .= self::multiplexingOptions($server);
                }
            } catch (\Throwable $e) {
                Log::warning('SSH multiplexing failed for SCP, falling back to non-multiplexed connection', [
                    'server' => $server->name ?? $server->ip,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $scpCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $scpCommand .= self::getCommonSshOptions($server, $sshKeyLocation, self::getConnectionTimeout($server), config('constants.ssh.server_interval'), isScp: true);

        // Upload: local source -> remote dest
        if ($server->isIpv6()) {
            return $scpCommand.escapeshellarg($source).' '.escapeshellarg($server->user).'@['.escapeshellarg($server->ip).']:'.escapeshellarg($dest);
        }

        return $scpCommand.escapeshellarg($source).' '.self::escapedUserAtHost($server).':'.escapeshellarg($dest);
    }

    /**
     * Build an SCP command that downloads a remote file onto the Coolify host.
     */
    public static function generateScpDownloadCommand(Server $server, string $remoteSource, string $localDest): string
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $scpCommand = 'timeout '.config('constants.ssh.command_timeout').' scp ';

        if ($server->isIpv6()) {
            $scpCommand .= '-6 ';
        }

        if (self::isMultiplexingEnabled()) {
            try {
                if (self::ensureMultiplexedConnection($server)) {
                    $scpCommand .= self::multiplexingOptions($server);
                }
            } catch (\Throwable $e) {
                Log::warning('SSH multiplexing failed for SCP download, falling back to non-multiplexed connection', [
                    'server' => $server->name ?? $server->ip,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $scpCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $scpCommand .= self::getCommonSshOptions($server, $sshKeyLocation, self::getConnectionTimeout($server), config('constants.ssh.server_interval'), isScp: true);

        // Download: remote source -> local dest
        if ($server->isIpv6()) {
            return $scpCommand.escapeshellarg($server->user).'@['.escapeshellarg($server->ip).']:'.escapeshellarg($remoteSource).' '.escapeshellarg($localDest);
        }

        return $scpCommand.self::escapedUserAtHost($server).':'.escapeshellarg($remoteSource).' '.escapeshellarg($localDest);
    }

    public static function generateSshCommand(Server $server, string $command, bool $disableMultiplexing = false, ?int $commandTimeout = null): string
    {
        if ($server->settings->force_disabled) {
            throw new \RuntimeException('Server is disabled.');
        }

        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];

        self::validateSshKey($server->privateKey);

        $commandTimeout = $commandTimeout ?? (int) config('constants.ssh.command_timeout');
        $sshCommand = $commandTimeout > 0 ? "timeout {$commandTimeout} ssh " : 'ssh ';

        if (! $disableMultiplexing && self::isMultiplexingEnabled()) {
            try {
                if (self::ensureMultiplexedConnection($server)) {
                    $sshCommand .= self::multiplexingOptions($server);
                }
            } catch (\Throwable $e) {
                Log::warning('SSH multiplexing failed, falling back to non-multiplexed connection', [
                    'server' => $server->name ?? $server->ip,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $sshCommand .= "-o ProxyCommand='cloudflared access ssh --hostname %h' ";
        }

        $sshCommand .= self::getCommonSshOptions($server, $sshKeyLocation, self::getConnectionTimeout($server), config('constants.ssh.server_interval'));

        $delimiter = base64_encode(Hash::make($command));
        $command = str_replace($delimiter, '', $command);

        return $sshCommand.self::escapedUserAtHost($server)." 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;
    }

    public static function getConnectionTimeout(Server $server): int
    {
        $timeout = data_get($server, 'settings.connection_timeout');

        return is_numeric($timeout) && (int) $timeout > 0
            ? (int) $timeout
            : (int) config('constants.ssh.connection_timeout');
    }

    public static function isConnectionHealthy(Server $server): bool
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];
        $healthCheckTimeout = config('constants.ssh.mux_health_check_timeout');

        $healthCommand = "timeout $healthCheckTimeout ssh -o ControlMaster=auto -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $healthCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $healthCommand .= self::escapedUserAtHost($server)." 'echo \"health_check_ok\"'";

        $process = Process::run($healthCommand);

        return $process->exitCode() === 0 && str_contains($process->output(), 'health_check_ok');
    }

    public static function refreshMultiplexedConnection(Server $server): bool
    {
        self::removeMuxFile($server);

        return self::establishNewMultiplexedConnection($server);
    }

    private static function connectionLockKey(Server $server): string
    {
        return 'ssh_mux_lock_'.(gethostname() ?: 'unknown').'_'.$server->uuid;
    }

    private static function muxProcessRetirementKey(string $pid, string $muxSocket, ?string $processStartTime): string
    {
        return 'ssh_mux_retiring_'.hash('sha256', self::processScope().'|'.$pid.'|'.$processStartTime.'|'.$muxSocket);
    }

    private static function processScope(): string
    {
        return (gethostname() ?: 'unknown').'|'.(@readlink('/proc/self/ns/pid') ?: 'unknown');
    }

    private static function processStartTime(string $pid): ?string
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false || ! preg_match('/^\d+ \(.*\) (.*)$/', trim($stat), $matches)) {
            return null;
        }

        $fields = preg_split('/\s+/', $matches[1]);

        return $fields[19] ?? null;
    }

    private static function masterConnectionExists(Server $server): bool
    {
        return Process::run(self::muxControlCommand($server, 'check'))->exitCode() === 0;
    }

    private static function connectionIsReusable(Server $server): bool
    {
        if (! self::masterConnectionExists($server)) {
            return false;
        }

        if (config('constants.ssh.mux_health_check_enabled') && ! self::isConnectionHealthy($server)) {
            return false;
        }

        return true;
    }

    private static function muxControlCommand(Server $server, string $operation): string
    {
        $command = "ssh -O {$operation} -o ControlPath=".self::muxSocket($server).' ';
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $command .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        return $command.self::escapedUserAtHost($server);
    }

    private static function multiplexingOptions(Server $server): string
    {
        return '-o ControlMaster=auto '
            .'-o ControlPath='.self::muxSocket($server).' '
            .'-o ControlPersist='.config('constants.ssh.mux_persist_time').' ';
    }

    private static function muxSocket(Server $server): string
    {
        return '/var/www/html/storage/app/ssh/mux/mux_'.$server->uuid;
    }

    private static function escapedUserAtHost(Server $server): string
    {
        return escapeshellarg($server->user).'@'.escapeshellarg($server->ip);
    }

    private static function isMultiplexingEnabled(): bool
    {
        return config('constants.ssh.mux_enabled') && ! config('constants.coolify.is_windows_docker_desktop');
    }

    private static function validateSshKey(PrivateKey $privateKey): void
    {
        $keyLocation = $privateKey->getKeyLocation();
        $filename = "ssh_key@{$privateKey->uuid}";
        $disk = Storage::disk('ssh-keys');

        $needsRewrite = false;

        if (! $disk->exists($filename)) {
            $needsRewrite = true;
        } else {
            $diskContent = $disk->get($filename);
            if ($diskContent !== $privateKey->private_key) {
                Log::warning('SSH key file content does not match database, resyncing', [
                    'key_uuid' => $privateKey->uuid,
                ]);
                $needsRewrite = true;
            }
        }

        if ($needsRewrite) {
            $privateKey->storeInFileSystem();
        }

        if (file_exists($keyLocation)) {
            $currentPerms = fileperms($keyLocation) & 0777;
            if ($currentPerms !== 0600 && ! chmod($keyLocation, 0600)) {
                Log::warning('Failed to set SSH key file permissions to 0600', [
                    'key_uuid' => $privateKey->uuid,
                    'path' => $keyLocation,
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

        if ($isScp) {
            return $options.'-P '.escapeshellarg((string) $server->port).' ';
        }

        return $options.'-p '.escapeshellarg((string) $server->port).' ';
    }
}
