<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Facades\Hash;

class SshCommandGeneratorService
{
    public function generateSshCommand(Server $server, string $command): string
    {
        if ($server->settings->force_disabled) {
            throw new \RuntimeException('Server is disabled.');
        }

        // TODO: Refactor this function
        $privateKeyLocation = savePrivateKeyToFs($server);

        $user = $server->user;
        $port = $server->port;

        $timeout = config('constants.ssh.command_timeout');
        $connectionTimeout = config('constants.ssh.connection_timeout');
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $ssh_command = "timeout $timeout ssh ";

        if (config('coolify.mux_enabled') && config('coolify.is_windows_docker_desktop') == false) {
            $ssh_command .= "-o ControlMaster=auto -o ControlPersist={$muxPersistTime} -o ControlPath=/var/www/html/storage/app/ssh/mux/%h_%p_%r ";
        }
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $ssh_command .= '-o ProxyCommand="/usr/local/bin/cloudflared access ssh --hostname %h" ';
        }
        $command = "PATH=\$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin && $command";
        $delimiter = Hash::make($command);
        $command = str_replace($delimiter, '', $command);
        $ssh_command .= "-i {$privateKeyLocation} "
            .'-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            .'-o PasswordAuthentication=no '
            ."-o ConnectTimeout=$connectionTimeout "
            ."-o ServerAliveInterval=$serverInterval "
            .'-o RequestTTY=no '
            .'-o LogLevel=ERROR '
            ."-p {$port} "
            ."{$user}@{$server->ip} "
            ." 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;

        return $ssh_command;
    }
}
