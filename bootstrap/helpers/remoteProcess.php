<?php

use App\Actions\CoolifyTask\PrepareCoolifyTask;
use App\Data\CoolifyTaskArgs;
use App\Enums\ActivityTypes;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;

/**
 * Run a Remote Process, which SSH's asynchronously into a machine to run the command(s).
 * @TODO Change 'root' to 'coolify' when it's able to run Docker commands without sudo
 *
 */
function remote_process(
    array   $command,
    Server  $server,
    string $type = ActivityTypes::INLINE->value,
    ?string $type_uuid = null,
    ?Model  $model = null,
): Activity {

    $command_string = implode("\n", $command);

    // @TODO: Check if the user has access to this server
    // checkTeam($server->team_id);

    $private_key_location = save_private_key_for_server($server);

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
function save_private_key_for_server(Server $server)
{
    if (data_get($server, 'privateKey.private_key') === null) {
        $server->settings->is_validated = false;
        $server->settings->save();
        throw new \Exception("Server {$server->name} does not have a private key");
    }
    $temp_file = "id.root@{$server->ip}";
    Storage::disk('ssh-keys')->put($temp_file, $server->privateKey->private_key);
    Storage::disk('ssh-mux')->makeDirectory('.');
    return '/var/www/html/storage/app/ssh/keys/' . $temp_file;
}

function generate_ssh_command(string $private_key_location, string $server_ip, string $user, string $port, string $command, bool $isMux = true)
{
    $delimiter = 'EOF-COOLIFY-SSH';
    $ssh_command = "ssh ";

    if ($isMux && config('coolify.mux_enabled')) {
        $ssh_command .= '-o ControlMaster=auto -o ControlPersist=1m -o ControlPath=/var/www/html/storage/app/ssh/mux/%h_%p_%r ';
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

function instant_remote_process(array $command, Server $server, $throwError = true, $repeat = 1)
{
    $command_string = implode("\n", $command);
    $private_key_location = save_private_key_for_server($server);
    $ssh_command = generate_ssh_command($private_key_location, $server->ip, $server->user, $server->port, $command_string);
    $process = Process::run($ssh_command);
    $output = trim($process->output());
    $exitCode = $process->exitCode();
    if ($exitCode !== 0) {
        if ($repeat > 1) {
            Sleep::for(200)->milliseconds();
            ray('executing again');
            return instant_remote_process($command, $server, $throwError, $repeat - 1);
        }
        // ray('ERROR OCCURED: ' . $process->errorOutput());
        if (!$throwError) {
            return null;
        }
        throw new \RuntimeException($process->errorOutput());
    }
    return $output;
}
