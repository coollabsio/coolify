<?php

namespace App\Helpers;

use App\Models\Server;
use App\Models\PrivateKey;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

class SshMultiplexingHelper
{
    public static function serverSshConfiguration(Server $server)
    {
        $privateKey = PrivateKey::findOrFail($server->private_key_id);
        $sshKeyLocation = $privateKey->getKeyLocation();
        $muxFilename = '/var/www/html/storage/app/ssh/mux/' . $server->muxFilename();

        return [
            'sshKeyLocation' => $sshKeyLocation,
            'muxFilename' => $muxFilename,
        ];
    }

    public static function ensureMultiplexedConnection(Server $server)
    {
        if (!self::isMultiplexingEnabled()) {
            ray('SSH Multiplexing: DISABLED')->red();
            return;
        }

        ray('SSH Multiplexing: ENABLED')->green();
        ray('Ensuring multiplexed connection for server:', $server->id);

        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];
        $sshKeyLocation = $sshConfig['sshKeyLocation'];

        self::validateSshKey($sshKeyLocation);

        $checkCommand = "ssh -O check -o ControlPath=$muxSocket {$server->user}@{$server->ip}";
        $process = Process::run($checkCommand);

        if ($process->exitCode() !== 0) {
            ray('SSH Multiplexing: Existing connection check failed or not found')->orange();
            ray('Establishing new connection');
            self::establishNewMultiplexedConnection($server);
        } else {
            ray('SSH Multiplexing: Existing connection is valid')->green();
        }
    }

    public static function establishNewMultiplexedConnection(Server $server)
    {
        ray('SSH Multiplexing: Establishing new connection for server:', $server->id);

        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $connectionTimeout = config('constants.ssh.connection_timeout');
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $establishCommand = "ssh -fNM -o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} "
            . "-i {$sshKeyLocation} "
            . '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . '-o PasswordAuthentication=no '
            . "-o ConnectTimeout=$connectionTimeout "
            . "-o ServerAliveInterval=$serverInterval "
            . '-o RequestTTY=no '
            . '-o LogLevel=ERROR '
            . "-p {$server->port} "
            . "{$server->user}@{$server->ip}";

        $establishProcess = Process::run($establishCommand);

        if ($establishProcess->exitCode() !== 0) {
            ray('SSH Multiplexing: Failed to establish connection', $establishProcess->errorOutput())->red();
            throw new \RuntimeException('Failed to establish multiplexed connection: ' . $establishProcess->errorOutput());
        }

        ray('SSH Multiplexing: Connection established successfully')->green();

        $muxContent = "Multiplexed connection established at " . now()->toDateTimeString();
        Storage::disk('ssh-mux')->put(basename($muxSocket), $muxContent);
    }

    public static function removeMuxFile(Server $server)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxFilename = basename($sshConfig['muxFilename']);
        
        $closeCommand = "ssh -O exit -o ControlPath=/var/www/html/storage/app/ssh/mux/{$muxFilename} {$server->user}@{$server->ip}";
        Process::run($closeCommand);

        Storage::disk('ssh-mux')->delete($muxFilename);
    }

    public static function generateScpCommand(Server $server, string $source, string $dest)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $connectionTimeout = config('constants.ssh.connection_timeout');
        $serverInterval = config('constants.ssh.server_interval');

        $scp_command = "timeout $timeout scp ";

        if (self::isMultiplexingEnabled()) {
            ray('SSH Multiplexing: Enabled for SCP command')->green();
            $muxPersistTime = config('constants.ssh.mux_persist_time');
            $scp_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
            self::ensureMultiplexedConnection($server);
            
            // Add this line to verify multiplexing is being used
            ray('SSH Multiplexing: Verifying usage')->blue();
            $checkCommand = "ssh -O check -o ControlPath=$muxSocket {$server->user}@{$server->ip}";
            $checkProcess = Process::run($checkCommand);
            ray('SSH Multiplexing: ' . ($checkProcess->exitCode() === 0 ? 'Active' : 'Not Active'))->color($checkProcess->exitCode() === 0 ? 'green' : 'red');
        } else {
            ray('SSH Multiplexing: Disabled for SCP command')->orange();
        }

        self::addCloudflareProxyCommand($scp_command, $server);

        $scp_command .= self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval);
        $scp_command .= "{$source} {$server->user}@{$server->ip}:{$dest}";

        return $scp_command;
    }

    public static function generateSshCommand(Server $server, string $command)
    {
        if ($server->settings->force_disabled) {
            throw new \RuntimeException('Server is disabled.');
        }

        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $connectionTimeout = config('constants.ssh.connection_timeout');
        $serverInterval = config('constants.ssh.server_interval');

        $ssh_command = "timeout $timeout ssh ";

        if (self::isMultiplexingEnabled()) {
            ray('SSH Multiplexing: Enabled for SSH command')->green();
            $muxPersistTime = config('constants.ssh.mux_persist_time');
            $ssh_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
            self::ensureMultiplexedConnection($server);
            
            // Add this line to verify multiplexing is being used
            ray('SSH Multiplexing: Verifying usage')->blue();
            $checkCommand = "ssh -O check -o ControlPath=$muxSocket {$server->user}@{$server->ip}";
            $checkProcess = Process::run($checkCommand);
            ray('SSH Multiplexing: ' . ($checkProcess->exitCode() === 0 ? 'Active' : 'Not Active'))->color($checkProcess->exitCode() === 0 ? 'green' : 'red');
        } else {
            ray('SSH Multiplexing: Disabled for SSH command')->orange();
        }

        self::addCloudflareProxyCommand($ssh_command, $server);

        $ssh_command .= self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval);

        $command = "PATH=\$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin && $command";
        $delimiter = Hash::make($command);
        $command = str_replace($delimiter, '', $command);

        $ssh_command .= "{$server->user}@{$server->ip} 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;

        return $ssh_command;
    }

    private static function isMultiplexingEnabled(): bool
    {
        $isEnabled = config('constants.ssh.mux_enabled') && !config('coolify.is_windows_docker_desktop');
        ray('SSH Multiplexing Status:', $isEnabled ? 'ENABLED' : 'DISABLED')->color($isEnabled ? 'green' : 'red');
        return $isEnabled;
    }

    private static function validateSshKey(string $sshKeyLocation): void
    {
        $checkKeyCommand = "ls $sshKeyLocation 2>/dev/null";
        $keyCheckProcess = Process::run($checkKeyCommand);

        if ($keyCheckProcess->exitCode() !== 0) {
            throw new \RuntimeException("SSH key file not accessible: $sshKeyLocation");
        }
    }

    private static function addCloudflareProxyCommand(string &$command, Server $server): void
    {
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $command .= '-o ProxyCommand="/usr/local/bin/cloudflared access ssh --hostname %h" ';
        }
    }

    private static function getCommonSshOptions(Server $server, string $sshKeyLocation, int $connectionTimeout, int $serverInterval): string
    {
        return "-i {$sshKeyLocation} "
            .'-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            .'-o PasswordAuthentication=no '
            ."-o ConnectTimeout=$connectionTimeout "
            ."-o ServerAliveInterval=$serverInterval "
            .'-o RequestTTY=no '
            .'-o LogLevel=ERROR '
            ."-p {$server->port} ";
    }
}