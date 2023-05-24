<?php

use App\Data\CoolifyTaskArgs;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

if (!function_exists('remoteProcess')) {
    /**
     * Run a Remote Process, which SSH's asynchronously into a machine to run the command(s).
     * @TODO Change 'root' to 'coolify' when it's able to run Docker commands without sudo
     *
     */
    function remoteProcess(
        array   $command,
        Server  $server,
        string $type,
        ?string $type_uuid = null,
        ?Model  $model = null,
    ): Activity {

        $command_string = implode("\n", $command);

        // @TODO: Check if the user has access to this server
        // checkTeam($server->team_id);

        $private_key_location = savePrivateKeyForServer($server);

        return resolve(PrepareCoolifyTask::class, [
            'remoteProcessArgs' => new CoolifyTaskArgs(
                server_ip: $server->ip,
                private_key_location: $private_key_location,
                command: <<<EOT
                {$command_string}
                EOT,
                port: $server->port,
                user: $server->user,
                type: $type,
                type_uuid: $type_uuid,
                model: $model,
            ),
        ])();
    }
}

if (!function_exists('savePrivateKeyForServer')) {
    function savePrivateKeyForServer(Server $server)
    {
        $temp_file = "id.root@{$server->ip}";
        Storage::disk('ssh-keys')->put($temp_file, $server->privateKey->private_key);
        return '/var/www/html/storage/app/ssh-keys/' . $temp_file;
    }
}

if (!function_exists('generateSshCommand')) {
    function generateSshCommand(string $private_key_location, string $server_ip, string $user, string $port, string $command, bool $isMux = true)
    {
        Storage::disk('local')->makeDirectory('.ssh');

        $delimiter = 'EOF-COOLIFY-SSH';
        $ssh_command = "ssh ";

        if ($isMux && config('coolify.mux_enabled')) {
            $ssh_command .= '-o ControlMaster=auto -o ControlPersist=1m -o ControlPath=/var/www/html/storage/app/.ssh/ssh_mux_%h_%p_%r ';
        }
        $ssh_command .= "-i {$private_key_location} "
            . '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . '-o PasswordAuthentication=no '
            . '-o ConnectTimeout=3600 '
            . '-o ServerAliveInterval=20 '
            . '-o RequestTTY=no '
            . '-o LogLevel=ERROR '
            . "-p {$port} "
            . "{$user}@{$server_ip} "
            . " 'bash -se' << \\$delimiter" . PHP_EOL
            . $command . PHP_EOL
            . $delimiter;

        return $ssh_command;
    }
}

if (!function_exists('instantRemoteProcess')) {
    function instantRemoteProcess(array $command, Server $server, $throwError = true)
    {
        $command_string = implode("\n", $command);
        $private_key_location = savePrivateKeyForServer($server);
        $ssh_command = generateSshCommand($private_key_location, $server->ip, $server->user, $server->port, $command_string);
        $process = Process::run($ssh_command);
        $output = trim($process->output());
        $exitCode = $process->exitCode();
        if ($exitCode !== 0) {
            if (!$throwError) {
                return null;
            }
            throw new \RuntimeException($process->errorOutput());
        }
        return $output;
    }
}
