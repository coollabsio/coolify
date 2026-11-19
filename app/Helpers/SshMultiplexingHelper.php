<?php

namespace App\Helpers;

use App\Jobs\ServerConnectionCheckJob;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SshMultiplexingHelper
{
    /** The host key scan uses the connection timeout of the server, but it runs while Coolify builds an SSH command, so it stops after this time. */
    private const HOST_KEY_SCAN_MAX_CONNECT_TIMEOUT_SECONDS = 30;

    /** After a failed scan (server offline), commands and the connection check do not scan again for this time. */
    private const HOST_KEY_SCAN_RETRY_SECONDS = 600;

    /** Enforce mode cannot connect without a pinned key, so it scans again sooner. The scan only reads the key, it trusts nothing more. */
    private const HOST_KEY_SCAN_ENFORCE_RETRY_SECONDS = 60;

    /**
     * The warning that OpenSSH prints at the start of its own stderr when the server sends a host key that is not
     * the pinned key: the banner, the fingerprint, the known_hosts lines, and the "is disabled to avoid
     * man-in-the-middle attacks" lines of the login methods that it turns off.
     */
    private const HOST_KEY_CHANGED_WARNING_PATTERN = '/\A@+\r?\n@\s+WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!\s+@\r?\n@+\r?\n'
        .'(?:[^@\r\n].*\r?\n){0,20}?Offending .+ key in .+\r?\n(?:  remove with:\r?\n  ssh-keygen .+\r?\n)?'
        .'(?:(?:.+ is disabled to avoid man-in-the-middle attacks\.|UpdateHostkeys is disabled because the host key is not trusted\.)\r?\n)*/';

    /** Stderr that starts like the warning is held at most this long, so other output is never held without limit. */
    private const HOST_KEY_CHANGED_WARNING_MAX_BYTES = 16384;

    /** The unknown SSH_HOST_KEY_CHECKING value that this process already checked. */
    private static mixed $checkedUnknownHostKeyCheckingMode = null;

    public const HOST_KEY_CHANGED_MESSAGE = 'The SSH host key of this server changed, so Coolify did not connect. An admin must check the new key on the Security page of the server.';

    public static function serverSshConfiguration(Server $server): array
    {
        $privateKey = PrivateKey::where('team_id', $server->team_id)->findOrFail($server->private_key_id);

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

                $check = self::runMuxControlCommand($server, 'check');
                if ($check?->successful()) {
                    return self::refreshMultiplexedConnection($server);
                }

                // A wedged master accepts on its socket but never answers, and a new
                // `ssh -fN` would hang on it too. Remove the socket so a fresh master can bind.
                if ($check === null) {
                    File::delete(self::muxSocket($server));
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

    /**
     * A disabled server, such as the archive of a transferred server, gets no SSH or scp connection.
     */
    private static function assertNotDisabled(Server $server): void
    {
        // Read the saved flag too: a job that loaded the server before a transfer finished must stop.
        if ($server->settings->force_disabled || ($server->exists && $server->settings()->value('force_disabled'))) {
            throw new \RuntimeException('Server is disabled.');
        }
    }

    public static function establishNewMultiplexedConnection(Server $server): bool
    {
        self::assertNotDisabled($server);
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];
        $connectionTimeout = self::getConnectionTimeout($server);
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        // Not `ssh -fN`: when another process holds the control socket, ssh disables multiplexing and an
        // `-fN` connection then stays open forever. This form runs `true` and exits. Only a master that
        // ControlPersist moves to the background stays.
        $establishCommand = 'ssh -o ControlMaster=auto -o ControlPath='.escapeshellarg($muxSocket)." -o ControlPersist={$muxPersistTime} ";

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $establishCommand .= ' -o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $establishCommand .= self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval);
        $establishCommand .= '-- '.self::escapedUserAtHost($server).' true';

        $establishProcess = Process::run($establishCommand);
        self::noticeHostKeyMismatch($server, $establishProcess);
        if ($establishProcess->exitCode() !== 0) {
            return false;
        }

        return true;
    }

    public static function removeMuxFile(Server $server): void
    {
        $checkProcess = self::runMuxControlCommand($server, 'check');
        $pid = $checkProcess && preg_match('/pid=(\d+)/', $checkProcess->output().$checkProcess->errorOutput(), $matches)
            ? $matches[1]
            : null;

        if ($pid !== null) {
            self::markMuxProcessAsRetiring($pid, self::muxSocket($server));
        }

        $stopProcess = self::runMuxControlCommand($server, 'stop');

        if ($stopProcess?->successful()) {
            return;
        }

        if ($pid !== null) {
            self::unmarkMuxProcessAsRetiring($pid, self::muxSocket($server));
        }

        // A master that does not stop must not stay reusable under the old user, IP, or port.
        // Without its socket it is an orphan, and the cleanup job reaps it.
        File::delete(self::muxSocket($server));
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
        self::assertNotDisabled($server);
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
            return $scpCommand.'-- '.escapeshellarg($source).' '.escapeshellarg($server->user).'@['.escapeshellarg($server->ip).']:'.escapeshellarg($dest);
        }

        return $scpCommand.'-- '.escapeshellarg($source).' '.self::escapedUserAtHost($server).':'.escapeshellarg($dest);
    }

    /**
     * Build an SCP command that downloads a remote file onto the Coolify host.
     */
    public static function generateScpDownloadCommand(Server $server, string $remoteSource, string $localDest): string
    {
        self::assertNotDisabled($server);
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
            return $scpCommand.'-- '.escapeshellarg($server->user).'@['.escapeshellarg($server->ip).']:'.escapeshellarg($remoteSource).' '.escapeshellarg($localDest);
        }

        return $scpCommand.'-- '.self::escapedUserAtHost($server).':'.escapeshellarg($remoteSource).' '.escapeshellarg($localDest);
    }

    public static function generateSshCommand(Server $server, string $command, bool $disableMultiplexing = false, ?int $commandTimeout = null): string
    {
        $sshCommand = self::sshCommandPrefix($server, $disableMultiplexing, $commandTimeout);

        $delimiter = base64_encode(Hash::make($command));
        $command = str_replace($delimiter, '', $command);
        $remoteShellCommand = self::remoteShellCommand();

        return $sshCommand.'-- '.self::escapedUserAtHost($server)." '{$remoteShellCommand}' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;
    }

    /**
     * Build an SSH command that runs the command batch given to the process as input (see sshBatchInput()).
     * The batch is not part of any command line on the Coolify host, where `ps` would show its secrets
     * (database passwords, registry passwords, tokens), and it is not limited to 128 KiB.
     * Like the quoted heredoc of generateSshCommand(), the batch gets no local shell expansion.
     */
    public static function generateSshBatchCommand(Server $server, bool $disableMultiplexing = false, ?int $commandTimeout = null): string
    {
        return self::generateSshStdinCommand($server, self::remoteShellCommand(), $disableMultiplexing, $commandTimeout);
    }

    /**
     * The process input for generateSshBatchCommand(). A heredoc ends its content with a newline, so does this.
     */
    public static function sshBatchInput(string $command): string
    {
        return $command.PHP_EOL;
    }

    /**
     * Build an SSH command that runs one short remote command and leaves stdin free for data.
     * Large content must go through stdin: Linux limits one exec argument to 128 KiB, and
     * generateSshCommand() puts the whole script into one `sh -c` argument.
     */
    public static function generateSshStdinCommand(Server $server, string $remoteCommand, bool $disableMultiplexing = false, ?int $commandTimeout = null): string
    {
        return self::sshCommandPrefix($server, $disableMultiplexing, $commandTimeout)
            .'-- '.self::escapedUserAtHost($server).' '.escapeshellarg($remoteCommand);
    }

    private static function sshCommandPrefix(Server $server, bool $disableMultiplexing, ?int $commandTimeout): string
    {
        self::assertNotDisabled($server);

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

        return $sshCommand.self::getCommonSshOptions($server, $sshKeyLocation, self::getConnectionTimeout($server), config('constants.ssh.server_interval'));
    }

    /**
     * sshd runs this with the SSH user's login shell, which can be fish or csh/tcsh. They cannot
     * parse POSIX `if ... fi`, so the logic runs in `sh`; bash is preferred, Alpine may lack it.
     */
    private static function remoteShellCommand(): string
    {
        return 'sh -c "if command -v bash >/dev/null 2>&1; then exec bash -se; else exec sh -se; fi"';
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
        // A real round trip through the master. If the master is gone, ssh connects directly,
        // so it needs the same options as every other connection. ControlMaster=no keeps it from becoming a master.
        $healthCommand = 'ssh -o ControlMaster=no -o ControlPath='.escapeshellarg($muxSocket).' ';
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $healthCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $healthCommand .= self::getCommonSshOptions($server, $sshConfig['sshKeyLocation'], self::getConnectionTimeout($server), config('constants.ssh.server_interval'));
        $healthCommand .= '-- '.self::escapedUserAtHost($server)." 'echo \"health_check_ok\"'";

        // The health check goes over the network, so a slow link gets at least the server's connection timeout.
        $timeout = max((int) config('constants.ssh.mux_health_check_timeout'), self::getConnectionTimeout($server));
        $process = self::runWithTimeout($healthCommand, $timeout);

        return $process?->exitCode() === 0 && str_contains($process->output(), 'health_check_ok');
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
        return self::runMuxControlCommand($server, 'check')?->successful() === true;
    }

    /**
     * Run `ssh -O <operation>` against a mux socket file. Returns null when the master does not answer in time.
     */
    public static function runMuxSocketCommand(string $muxSocket, string $operation): ?ProcessResult
    {
        return self::runWithTimeout(
            "ssh -F /dev/null -O {$operation} -o ControlPath=".escapeshellarg($muxSocket).' localhost',
            (int) config('constants.ssh.mux_health_check_timeout'),
        );
    }

    private static function runMuxControlCommand(Server $server, string $operation): ?ProcessResult
    {
        return self::runWithTimeout(self::muxControlCommand($server, $operation), (int) config('constants.ssh.mux_health_check_timeout'));
    }

    /**
     * Use a PHP-side timeout, not the `timeout` binary: BusyBox `timeout` in the Coolify image
     * kills ssh with SIGTERM, and Symfony turns that into an exception. Returns null on timeout.
     */
    private static function runWithTimeout(string $command, int $timeout): ?ProcessResult
    {
        try {
            return Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException) {
            return null;
        }
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
        $command = "ssh -F /dev/null -O {$operation} -o ControlPath=".escapeshellarg(self::muxSocket($server)).' ';
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $command .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        return $command.'-- '.self::escapedUserAtHost($server);
    }

    private static function multiplexingOptions(Server $server): string
    {
        return '-o ControlMaster=auto '
            .'-o ControlPath='.escapeshellarg(self::muxSocket($server)).' '
            .'-o ControlPersist='.config('constants.ssh.mux_persist_time').' ';
    }

    private static function muxSocket(Server $server): string
    {
        return '/var/www/html/storage/app/ssh/mux/mux_'.$server->uuid;
    }

    private static function escapedUserAtHost(Server $server): string
    {
        // ssh and scp read an argument that starts with "-" as an option, also after escaping.
        // Validation rejects such values; this also stops rows that were saved before it did.
        if (str_starts_with((string) $server->user, '-') || str_starts_with((string) $server->ip, '-')) {
            throw new \RuntimeException('The SSH user and the server IP must not start with "-".');
        }

        return escapeshellarg($server->user).'@'.escapeshellarg($server->ip);
    }

    private static function isMultiplexingEnabled(): bool
    {
        return config('constants.ssh.mux_enabled');
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
        self::giveToSshDirectoryOwner($keyLocation, $keyLocation.'.lock');
    }

    private static function getCommonSshOptions(Server $server, string $sshKeyLocation, int $connectionTimeout, int $serverInterval, bool $isScp = false): string
    {
        $options = '-i '.escapeshellarg($sshKeyLocation).' '
            .self::hostKeyOptions($server)
            .'-o PasswordAuthentication=no '
            ."-o ConnectTimeout=$connectionTimeout "
            ."-o ServerAliveInterval=$serverInterval "
            .'-F /dev/null -o IdentitiesOnly=yes '
            .'-o ClearAllForwardings=yes -o ForwardAgent=no '
            .'-o RequestTTY=no '
            .'-o LogLevel=ERROR ';

        if ($isScp) {
            return $options.'-P '.escapeshellarg((string) $server->port).' ';
        }

        return $options.'-p '.escapeshellarg((string) $server->port).' ';
    }

    /**
     * warn: connect also when the host key changed, and report it. enforce: refuse a changed host key. off: no check.
     */
    public static function hostKeyCheckingMode(Server $server): string
    {
        $mode = config('constants.ssh.host_key_checking');
        if (! in_array($mode, ['warn', 'enforce', 'off'], true)) {
            self::warnAboutUnknownHostKeyCheckingMode($mode);
        }
        if ($mode === 'off' || data_get($server, 'settings.is_ssh_host_key_check_disabled')) {
            return 'off';
        }

        // A team can make its own server stricter than the instance, never weaker than an instance "off".
        return $mode === 'enforce' || data_get($server, 'settings.is_ssh_host_key_check_enforced') ? 'enforce' : 'warn';
    }

    /**
     * A mistyped SSH_HOST_KEY_CHECKING value falls back to warn. Log it once a day, not for every command.
     */
    private static function warnAboutUnknownHostKeyCheckingMode(mixed $mode): void
    {
        if (self::$checkedUnknownHostKeyCheckingMode === $mode) {
            return;
        }
        self::$checkedUnknownHostKeyCheckingMode = $mode;
        if (Cache::add('ssh_host_key_checking_unknown_warned_'.hash('sha256', (string) $mode), true, now()->addDay())) {
            Log::warning('Unknown SSH_HOST_KEY_CHECKING value, Coolify uses warn. Use warn, enforce, or off.', ['value' => $mode]);
        }
    }

    /**
     * True when ssh reports that the server sent a host key that does not match the pinned key.
     */
    public static function isHostKeyMismatch(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'REMOTE HOST IDENTIFICATION HAS CHANGED')
            || str_contains($errorOutput, 'Host key verification failed');
    }

    /**
     * When an SSH process reports a changed host key, check the connection once. The check saves the new key
     * as pending and notifies the team, also for a server that the scheduled connection check skips (Sentinel).
     * The message must name the host key alias or the known_hosts file of the server: a program on the server
     * (git in a deployment) can print the same text for its own SSH connection. Without strict checking (warn
     * mode), ssh names only the known_hosts file.
     */
    public static function noticeHostKeyMismatch(Server $server, ProcessResult $result): void
    {
        $errorOutput = $result->errorOutput();
        $namesThisServer = str_contains($errorOutput, self::hostKeyAlias($server))
            || str_contains($errorOutput, '/known_hosts_'.$server->uuid.'_');
        if (! self::isHostKeyMismatch($errorOutput) || ! $namesThisServer
            || ! Cache::add('ssh_host_key_mismatch_noticed_'.$server->uuid, true, 600)) {
            return;
        }
        ServerConnectionCheckJob::dispatch($server);
    }

    /**
     * Remove the changed host key warning of OpenSSH from the start of the stderr of an SSH process, for logs and
     * saved output. Detect a mismatch with the raw output (isHostKeyMismatch()). A warning later in the output
     * is from a program on the server (git) and stays.
     */
    public static function stripHostKeyChangedWarning(string $output): string
    {
        return preg_replace(self::HOST_KEY_CHANGED_WARNING_PATTERN, '', $output, 1) ?? $output;
    }

    /**
     * Filter streamed stderr of an SSH process: hold the first chunks while they can still be the changed host key
     * warning, then return them without it. Start with $held = ''; it becomes null when the start is passed.
     * Pass $final when the warning must be over (stdout arrived or the process ended).
     */
    public static function filterHostKeyChangedWarning(?string &$held, string $chunk, bool $final = false): string
    {
        if ($held === null) {
            return $chunk;
        }
        $held .= $chunk;
        if (! $final && self::mayBeHostKeyChangedWarning($held)) {
            return '';
        }
        $output = self::stripHostKeyChangedWarning($held);
        $held = null;

        return $output;
    }

    private static function mayBeHostKeyChangedWarning(string $output): bool
    {
        if ($output === '') {
            return true;
        }
        if (! str_starts_with($output, '@') || strlen($output) > self::HOST_KEY_CHANGED_WARNING_MAX_BYTES) {
            return false;
        }
        if (! preg_match(self::HOST_KEY_CHANGED_WARNING_PATTERN, $output, $matches)) {
            return true;
        }

        // The rest of the warning can follow until a complete line arrives that is not part of it.
        return ! preg_match('/\A(?!  remove with:).*\n/', substr($output, strlen($matches[0])));
    }

    /**
     * Read the host key that the server sends now. The probe has every login method off, so it offers no key
     * and runs no command: ssh saves the host key during the key exchange, then the login fails by design.
     */
    public static function scanHostKey(Server $server): ?string
    {
        return self::readHostKey($server)['key'];
    }

    /**
     * @return array{key: ?string, error: string}
     */
    private static function readHostKey(Server $server): array
    {
        $connectTimeout = self::hostKeyScanConnectTimeout($server);
        $directory = self::knownHostsDirectory();
        File::ensureDirectoryExists($directory, 0700);
        self::giveToSshDirectoryOwner($directory);
        $scanFile = $directory.'/scan_'.$server->uuid.'_'.Str::random(8);

        $command = 'ssh ';
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $command .= "-o ProxyCommand='cloudflared access ssh --hostname %h' ";
        }
        $command .= '-F /dev/null -o HostKeyAlias='.escapeshellarg(self::hostKeyAlias($server)).' '
            .'-o UserKnownHostsFile='.escapeshellarg($scanFile).' -o GlobalKnownHostsFile=/dev/null '
            .'-o StrictHostKeyChecking=accept-new -o BatchMode=yes '
            .'-o PubkeyAuthentication=no -o PasswordAuthentication=no -o KbdInteractiveAuthentication=no '
            .'-o HostbasedAuthentication=no '
            .'-o ConnectTimeout='.$connectTimeout.' -o LogLevel=ERROR '
            .'-p '.escapeshellarg((string) $server->port).' '
            .'-- '.self::escapedUserAtHost($server).' true';

        try {
            $result = self::runWithTimeout($command, self::hostKeyScanProcessTimeout($server));

            return [
                'key' => ServerSetting::normalizeSshHostKeys(@file_get_contents($scanFile) ?: null),
                'error' => $result === null ? 'The connection timed out.' : Str::limit(trim($result->errorOutput()), 300),
            ];
        } finally {
            File::delete([$scanFile, $scanFile.'.old']);
        }
    }

    /** The connection timeout of the server, like the other SSH commands use, capped for the scan. */
    private static function hostKeyScanConnectTimeout(Server $server): int
    {
        return min(self::getConnectionTimeout($server), self::HOST_KEY_SCAN_MAX_CONNECT_TIMEOUT_SECONDS);
    }

    private static function hostKeyScanProcessTimeout(Server $server): int
    {
        return self::hostKeyScanConnectTimeout($server) + 5;
    }

    /**
     * Pin the host key that the server sends on the first connection (trust on first use).
     * Nothing scans again within HOST_KEY_SCAN_RETRY_SECONDS (enforce mode: HOST_KEY_SCAN_ENFORCE_RETRY_SECONDS) of a failed scan. Commands also do not scan a server
     * that is unreachable, so they do not wait for the scan timeout. The connection check passes
     * $evenIfUnreachable, so the server learns its key when it is back. In enforce mode the check then fails with
     * the scan error until the next scan.
     */
    public static function learnHostKey(Server $server, bool $evenIfUnreachable = false): void
    {
        if (self::hostKeyCheckingMode($server) === 'off' || filled($server->settings->ssh_host_key) || ! $server->settings->exists) {
            return;
        }
        if (Cache::has(self::hostKeyScanFailedCacheKey($server)) || (! $evenIfUnreachable && $server->unreachable_count > 0)) {
            return;
        }

        $learn = function () use ($server) {
            if (self::loadPinnedHostKey($server)) {
                return;
            }
            $scan = self::readHostKey($server);
            if ($scan['key'] === null) {
                $retrySeconds = self::hostKeyCheckingMode($server) === 'enforce' ? self::HOST_KEY_SCAN_ENFORCE_RETRY_SECONDS : self::HOST_KEY_SCAN_RETRY_SECONDS;
                Cache::put(self::hostKeyScanFailedCacheKey($server), $scan['error'], $retrySeconds);

                return;
            }
            $pin = [
                'ssh_host_key' => $scan['key'],
                // A server that was never usable is new. A usable server learns its key after the upgrade.
                'ssh_host_key_source' => $server->settings->is_usable ? 'upgrade' : 'tofu',
                'ssh_host_key_trusted_at' => now(),
                'ssh_host_key_pending' => null,
            ];
            // Write only the pin, so that unsaved settings changes of the caller stay unsaved.
            $pinned = ServerSetting::query()->whereKey($server->settings->getKey())->whereNull('ssh_host_key')->update($pin);
            if ($pinned === 0) {
                self::loadPinnedHostKey($server);

                return;
            }
            $server->settings->forceFill($pin)->syncOriginalAttributes(array_keys($pin));
            // An open multiplexed connection was made without the pinned key.
            self::removeMuxFile($server);
        };

        $lock = Cache::lock('ssh_host_key_learn_'.$server->uuid, self::HOST_KEY_SCAN_MAX_CONNECT_TIMEOUT_SECONDS + 25);
        try {
            // Enforce mode cannot connect without the key, so it waits for another process that reads it. Warn mode connects without it.
            if (self::hostKeyCheckingMode($server) === 'enforce') {
                $lock->block(self::hostKeyScanProcessTimeout($server), $learn);
            } else {
                $lock->get($learn);
            }
        } catch (LockTimeoutException) {
            self::loadPinnedHostKey($server);
        }
    }

    /**
     * Read the pinned host key from the database into the settings in memory, without a refresh() that drops unsaved changes.
     */
    private static function loadPinnedHostKey(Server $server): bool
    {
        $columns = ['ssh_host_key', 'ssh_host_key_source', 'ssh_host_key_trusted_at', 'ssh_host_key_pending'];
        $stored = ServerSetting::query()->whereKey($server->settings->getKey())->first($columns);
        if (blank($stored?->ssh_host_key)) {
            return false;
        }
        $server->settings->forceFill($stored->getAttributes())->syncOriginalAttributes($columns);

        return true;
    }

    /**
     * Read the saved host key settings into the settings in memory. A job that loaded the server before an admin
     * enforced the check, trusted a new key, or reset the key must not connect with the old settings.
     * Only the host key page changes these columns, and it saves them before it connects.
     */
    private static function loadSavedHostKeySettings(Server $server): void
    {
        if (! $server->settings->exists) {
            return;
        }
        $columns = ['ssh_host_key', 'ssh_host_key_source', 'ssh_host_key_trusted_at', 'ssh_host_key_pending', 'is_ssh_host_key_check_disabled', 'is_ssh_host_key_check_enforced'];
        $stored = ServerSetting::query()->whereKey($server->settings->getKey())->first($columns);
        if ($stored !== null) {
            $server->settings->forceFill($stored->getAttributes())->syncOriginalAttributes($columns);
        }
    }

    /**
     * Stop the multiplexed connection after a change of the host key settings. The lock makes sure that no other
     * process opens a connection with the old settings in the same moment: it opens it before the stop, or after it with the saved settings.
     */
    public static function removeMuxFileAfterHostKeyChange(Server $server): void
    {
        try {
            Cache::lock(self::connectionLockKey($server), config('constants.ssh.mux_lock_ttl'))
                ->block(config('constants.ssh.mux_lock_timeout'), fn () => self::removeMuxFile($server));
        } catch (LockTimeoutException) {
            self::removeMuxFile($server);
        }
    }

    /** The key has the mode, so a failure of a warn mode scan does not stop an enforce mode scan for its longer retry time. */
    private static function hostKeyScanFailedCacheKey(Server $server): string
    {
        return 'ssh_host_key_scan_failed_'.self::hostKeyCheckingMode($server).'_'.$server->uuid;
    }

    private static function hostKeyOptions(Server $server): string
    {
        self::loadSavedHostKeySettings($server);
        self::learnHostKey($server);
        $mode = self::hostKeyCheckingMode($server);

        if ($mode === 'off') {
            return '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null ';
        }
        if (blank($server->settings->ssh_host_key)) {
            if ($mode === 'enforce') {
                $scanError = Cache::get(self::hostKeyScanFailedCacheKey($server));
                throw new \RuntimeException('SSH connection failed: Coolify could not read the SSH host key of the server'.(filled($scanError) ? ": {$scanError}" : '.'), 255);
            }

            return '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null ';
        }

        return '-o HostKeyAlias='.escapeshellarg(self::hostKeyAlias($server)).' '
            .'-o UserKnownHostsFile='.escapeshellarg(self::syncKnownHostsFile($server)).' '
            .'-o StrictHostKeyChecking='.($mode === 'enforce' ? 'yes' : 'no').' ';
    }

    /**
     * Write the known_hosts file of the server from the database, like validateSshKey() does for the key file.
     * The file name has a hash of the content, so a process that loaded an older key cannot overwrite the file of the current key.
     */
    private static function syncKnownHostsFile(Server $server): string
    {
        ['path' => $path, 'content' => $content] = self::knownHostsFile($server->uuid, $server->settings->ssh_host_key);

        if (@file_get_contents($path) !== $content) {
            File::ensureDirectoryExists(self::knownHostsDirectory(), 0700);
            $temporaryPath = $path.'.'.Str::random(8);
            File::put($temporaryPath, $content);
            chmod($temporaryPath, 0600);
            rename($temporaryPath, $path);
        }
        self::giveToSshDirectoryOwner(self::knownHostsDirectory(), $path);

        return $path;
    }

    /**
     * Delete the known_hosts files of old host keys of a server. Pass null to delete every file of the server.
     */
    public static function deleteStaleKnownHostsFiles(string $serverUuid, ?string $pinnedKey): void
    {
        $keep = filled($pinnedKey) ? self::knownHostsFile($serverUuid, $pinnedKey)['path'] : null;
        foreach (File::glob(self::knownHostsDirectory().'/known_hosts_'.$serverUuid.'_*') as $file) {
            if ($file !== $keep && preg_match('/_[0-9a-f]{16}$/', $file) === 1) {
                File::delete($file);
            }
        }
    }

    /**
     * @return array{path: string, content: string}
     */
    private static function knownHostsFile(string $serverUuid, string $pinnedKey): array
    {
        $alias = 'coolify-'.$serverUuid;
        $content = collect(explode("\n", $pinnedKey))
            ->filter()
            ->map(fn (string $key) => "{$alias} {$key}\n")
            ->implode('');

        return [
            'path' => self::knownHostsDirectory().'/known_hosts_'.$serverUuid.'_'.substr(hash('sha256', $content), 0, 16),
            'content' => $content,
        ];
    }

    private static function knownHostsDirectory(): string
    {
        return storage_path('app/ssh/known_hosts');
    }

    /**
     * Horizon runs as root and php-fpm runs as www-data. Files that a root process writes under storage/app/ssh
     * get the owner of that directory, so that the web process can still read and replace them.
     */
    public static function giveToSshDirectoryOwner(string ...$paths): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $owner = @fileowner(storage_path('app/ssh'));
        $group = @filegroup(storage_path('app/ssh'));
        if ($owner === false || $owner === 0) {
            return;
        }

        foreach ($paths as $path) {
            if (file_exists($path) && (fileowner($path) !== $owner || filegroup($path) !== $group)) {
                @chown($path, $owner);
                @chgrp($path, $group);
            }
        }
    }

    private static function hostKeyAlias(Server $server): string
    {
        return 'coolify-'.$server->uuid;
    }
}
