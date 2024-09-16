<?php

namespace App\Helpers;

use App\Models\Server;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\PrivateKey;

class SshMultiplexingHelper
{
    protected static $ensuredConnections = [];

    public static function serverSshConfiguration(Server $server)
    {
        $sshKeyLocation = $server->privateKey->getKeyLocation();
        $muxFilename = '/var/www/html/storage/app/ssh/mux/' . $server->muxFilename();

        return [
            'sshKeyLocation' => $sshKeyLocation,
            'muxFilename' => $muxFilename,
        ];
    }

    public static function ensureMultiplexedConnection(Server $server)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];
        $sshKeyLocation = $sshConfig['sshKeyLocation'];

        if (!file_exists($sshKeyLocation)) {
            throw new \RuntimeException("SSH key file not accessible: $sshKeyLocation");
        }

        if (isset(self::$ensuredConnections[$server->id]) && !self::shouldResetMultiplexedConnection($server)) {
            return;
        }

        $checkFileCommand = "ls $muxSocket 2>/dev/null";
        $fileCheckProcess = Process::run($checkFileCommand);

        if ($fileCheckProcess->exitCode() !== 0) {
            self::establishNewMultiplexedConnection($server);
            return;
        }

        $checkCommand = "ssh -O check -o ControlPath=$muxSocket {$server->user}@{$server->ip}";
        $process = Process::run($checkCommand);

        if ($process->exitCode() === 0) {
            self::$ensuredConnections[$server->id] = [
                'timestamp' => now(),
                'muxSocket' => $muxSocket,
            ];
            return;
        }

        self::establishNewMultiplexedConnection($server);
    }

    public static function establishNewMultiplexedConnection(Server $server)
    {
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
            throw new \RuntimeException('Failed to establish multiplexed connection: ' . $establishProcess->errorOutput());
        }

        $muxContent = "Multiplexed connection established at " . now()->toDateTimeString();
        Storage::disk('ssh-mux')->put(basename($muxSocket), $muxContent);

        self::$ensuredConnections[$server->id] = [
            'timestamp' => now(),
            'muxSocket' => $muxSocket,
        ];
    }

    public static function shouldResetMultiplexedConnection(Server $server)
    {
        if (!(config('constants.ssh.mux_enabled') && config('coolify.is_windows_docker_desktop') == false)) {
            return false;
        }

        if (!isset(self::$ensuredConnections[$server->id])) {
            return true;
        }

        $lastEnsured = self::$ensuredConnections[$server->id]['timestamp'];
        $muxPersistTime = config('constants.ssh.mux_persist_time');
        $resetInterval = strtotime($muxPersistTime) - time();

        return $lastEnsured->addSeconds($resetInterval)->isPast();
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

        $user = $server->user;
        $port = $server->port;
        $timeout = config('constants.ssh.command_timeout');
        $connectionTimeout = config('constants.ssh.connection_timeout');
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $scp_command = "timeout $timeout scp ";
        $muxEnabled = config('constants.ssh.mux_enabled', true) && config('coolify.is_windows_docker_desktop') == false;
        ray('SSH Multiplexing Enabled:', $muxEnabled)->blue();

        if ($muxEnabled) {
            $scp_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
            self::ensureMultiplexedConnection($server);
            ray('Using SSH Multiplexing')->green();
        } else {
            ray('Not using SSH Multiplexing')->red();
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $scp_command .= '-o ProxyCommand="/usr/local/bin/cloudflared access ssh --hostname %h" ';
        }
        $scp_command .= "-i {$sshKeyLocation} "
            .'-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            .'-o PasswordAuthentication=no '
            ."-o ConnectTimeout=$connectionTimeout "
            ."-o ServerAliveInterval=$serverInterval "
            .'-o RequestTTY=no '
            .'-o LogLevel=ERROR '
            ."-P {$port} "
            ."{$source} "
            ."{$user}@{$server->ip}:{$dest}";

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
        $muxPersistTime = config('constants.ssh.mux_persist_time');
        $muxEnabled = config('constants.ssh.mux_enabled') && !config('coolify.is_windows_docker_desktop');
        ray('Config MUX Enabled:', config('constants.ssh.mux_enabled'));
        ray('Config Windows Docker Desktop:', config('coolify.is_windows_docker_desktop'));
        ray('MUX Enabled:', $muxEnabled);

        $ssh_command = "timeout $timeout ssh ";

        ray('SSH Multiplexing Enabled:', $muxEnabled)->blue();

        if ($muxEnabled) {
            $ssh_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
            self::ensureMultiplexedConnection($server);
            ray('Using SSH Multiplexing')->green();
        } else {
            ray('Not using SSH Multiplexing')->red();
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $ssh_command .= '-o ProxyCommand="/usr/local/bin/cloudflared access ssh --hostname %h" ';
        }

        $command = "PATH=\$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin && $command";
        $delimiter = Hash::make($command);
        $command = str_replace($delimiter, '', $command);

        $ssh_command .= "-i {$sshKeyLocation} "
            .'-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            .'-o PasswordAuthentication=no '
            ."-o ConnectTimeout=$connectionTimeout "
            ."-o ServerAliveInterval=$serverInterval "
            .'-o RequestTTY=no '
            .'-o LogLevel=ERROR '
            ."-p {$server->port} "
            ."{$server->user}@{$server->ip} "
            ." 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;

        return $ssh_command;
    }
}