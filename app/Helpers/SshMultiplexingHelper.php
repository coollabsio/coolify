<?php

namespace App\Helpers;

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Hash;
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

    public static function ensureMultiplexedConnection(Server $server)
    {
        if (! self::isMultiplexingEnabled()) {
            ray('SSH Multiplexing: DISABLED')->red();
            return;
        }

        ray('SSH Multiplexing: ENABLED')->green();
        ray('Ensuring multiplexed connection for server:', $server);

        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];
        $sshKeyLocation = $sshConfig['sshKeyLocation'];

        self::validateSshKey($sshKeyLocation);

        $checkCommand = "ssh -O check -o ControlPath=$muxSocket {$server->user}@{$server->ip}";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $checkCommand = 'cloudflared access ssh --hostname %h -O check -o ControlPath=' . $muxSocket . ' ' . $server->user . '@' . $server->ip;
        }
        ray('Check Command:', $checkCommand);
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
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        ray('Establishing new multiplexed connection')->blue();
        ray('SSH Key Location:', $sshKeyLocation);
        ray('Mux Socket:', $muxSocket);

        $connectionTimeout = config('constants.ssh.connection_timeout');
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $establishCommand = "ssh -fNM -o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} "
            .self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval)
            ."{$server->user}@{$server->ip}";

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $establishCommand = 'cloudflared access ssh --hostname %h -fNM -o ControlMaster=auto -o ControlPath=' . $muxSocket . ' -o ControlPersist=' . $muxPersistTime . ' ' . self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval) . $server->user . '@' . $server->ip;
        }

        ray('Establish Command:', $establishCommand);

        $establishProcess = Process::run($establishCommand);

        ray('Establish Process Exit Code:', $establishProcess->exitCode());
        ray('Establish Process Output:', $establishProcess->output());
        ray('Establish Process Error Output:', $establishProcess->errorOutput());

        if ($establishProcess->exitCode() !== 0) {
            ray('Failed to establish multiplexed connection')->red();
            throw new \RuntimeException('Failed to establish multiplexed connection: '.$establishProcess->errorOutput());
        }

        ray('Successfully established multiplexed connection')->green();

        // Check if the mux socket file was created
        if (! file_exists($muxSocket)) {
            ray('Mux socket file not found after connection establishment')->orange();
        }
    }

    public static function removeMuxFile(Server $server)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];

        $closeCommand = "ssh -O exit -o ControlPath=$muxSocket {$server->user}@{$server->ip}";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $closeCommand = 'cloudflared access ssh --hostname %h -O exit -o ControlPath=' . $muxSocket . ' ' . $server->user . '@' . $server->ip;
        }
        $process = Process::run($closeCommand);

        ray('Closing multiplexed connection')->blue();
        ray('Close command:', $closeCommand);
        ray('Close process exit code:', $process->exitCode());
        ray('Close process output:', $process->output());
        ray('Close process error output:', $process->errorOutput());

        if ($process->exitCode() !== 0) {
            ray('Failed to close multiplexed connection')->orange();
        } else {
            ray('Successfully closed multiplexed connection')->green();
        }
    }

    public static function generateScpCommand(Server $server, string $source, string $dest)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $scp_command = "timeout $timeout scp ";

        if (self::isMultiplexingEnabled()) {
            $scp_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
            self::ensureMultiplexedConnection($server);
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $scp_command = 'timeout ' . $timeout . ' cloudflared access ssh --hostname %h -o ControlMaster=auto -o ControlPath=' . $muxSocket . ' -o ControlPersist=' . $muxPersistTime . ' ';
        }

        $scp_command .= self::getCommonSshOptions($server, $sshKeyLocation, config('constants.ssh.connection_timeout'), config('constants.ssh.server_interval'), isScp: true);
        $scp_command .= "{$source} {$server->user}@{$server->ip}:{$dest}";

        ray('SCP Command:', $scp_command);

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
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $ssh_command = "timeout $timeout ssh ";

        if (self::isMultiplexingEnabled()) {
            $ssh_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
            self::ensureMultiplexedConnection($server);
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $ssh_command = 'timeout ' . $timeout . ' cloudflared access ssh --hostname %h -o ControlMaster=auto -o ControlPath=' . $muxSocket . ' -o ControlPersist=' . $muxPersistTime . ' ';
        }

        $ssh_command .= self::getCommonSshOptions($server, $sshKeyLocation, config('constants.ssh.connection_timeout'), config('constants.ssh.server_interval'));

        $command = "PATH=\$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin && $command";
        $delimiter = Hash::make($command);
        $command = str_replace($delimiter, '', $command);

        $ssh_command .= "{$server->user}@{$server->ip} 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;

        ray('SSH Command:', $ssh_command);

        return $ssh_command;
    }

    private static function isMultiplexingEnabled(): bool
    {
        return config('constants.ssh.mux_enabled') && ! config('coolify.is_windows_docker_desktop');
    }

    private static function validateSshKey(string $sshKeyLocation): void
    {
        $checkKeyCommand = "ls $sshKeyLocation 2>/dev/null";
        $keyCheckProcess = Process::run($checkKeyCommand);

        if ($keyCheckProcess->exitCode() !== 0) {
            throw new \RuntimeException("SSH key file not accessible: $sshKeyLocation");
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
}
