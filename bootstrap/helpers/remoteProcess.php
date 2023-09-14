<?php

use App\Actions\CoolifyTask\PrepareCoolifyTask;
use App\Data\CoolifyTaskArgs;
use App\Enums\ActivityTypes;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Notifications\Server\NotReachable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;

function remote_process(
    array   $command,
    Server  $server,
    string  $type = ActivityTypes::INLINE->value,
    ?string $type_uuid = null,
    ?Model  $model = null,
    bool    $ignore_errors = false,
): Activity {

    $command_string = implode("\n", $command);
    if (auth()->user()) {
        $teams = auth()->user()->teams->pluck('id');
        if (!$teams->contains($server->team_id) && !$teams->contains(0)) {
            throw new \Exception("User is not part of the team that owns this server");
        }
    }

    return resolve(PrepareCoolifyTask::class, [
        'remoteProcessArgs' => new CoolifyTaskArgs(
            server_ip: $server->ip,
            command: <<<EOT
                {$command_string}
                EOT,
            port: $server->port,
            user: $server->user,
            type: $type,
            type_uuid: $type_uuid,
            model: $model,
            ignore_errors: $ignore_errors
        ),
    ])();
}

function removePrivateKeyFromSshAgent(Server $server)
{
    if (data_get($server, 'privateKey.private_key') === null) {
        throw new \Exception("Server {$server->name} does not have a private key");
    }
    processWithEnv()->run("echo '{$server->privateKey->private_key}' | ssh-add -d -");
}
function addPrivateKeyToSshAgent(Server $server, bool $onlyRemove = false)
{
    if (data_get($server, 'privateKey.private_key') === null) {
        throw new \Exception("Server {$server->name} does not have a private key");
    }
    // ray('adding key', $server->privateKey->private_key);
    processWithEnv()->run("echo '{$server->privateKey->private_key}' | ssh-add -q -");
}

function generateSshCommand(string $server_ip, string $user, string $port, string $command, bool $isMux = true)
{
    $server = Server::where('ip', $server_ip)->first();
    if (!$server) {
        throw new \Exception("Server with ip {$server_ip} not found");
    }
    addPrivateKeyToSshAgent($server);
    $timeout = config('constants.ssh.command_timeout');
    $connectionTimeout = config('constants.ssh.connection_timeout');
    $serverInterval = config('constants.ssh.server_interval');

    $delimiter = 'EOF-COOLIFY-SSH';
    $ssh_command = "timeout $timeout ssh ";

    if ($isMux && config('coolify.mux_enabled')) {
        $ssh_command .= '-o ControlMaster=auto -o ControlPersist=1m -o ControlPath=/var/www/html/storage/app/ssh/mux/%h_%p_%r ';
    }
    $command = "PATH=\$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin && $command";
    $ssh_command .= '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
        . '-o PasswordAuthentication=no '
        . "-o ConnectTimeout=$connectionTimeout "
        . "-o ServerAliveInterval=$serverInterval "
        . '-o RequestTTY=no '
        . '-o LogLevel=ERROR '
        . "-p {$port} "
        . "{$user}@{$server_ip} "
        . " 'bash -se' << \\$delimiter" . PHP_EOL
        . $command . PHP_EOL
        . $delimiter;
    // ray($ssh_command);
    return $ssh_command;
}
function processWithEnv()
{
    return Process::env(['SSH_AUTH_SOCK' => config('coolify.ssh_auth_sock')]);
}
function instantCommand(string $command, $throwError = true)
{
    $process = processWithEnv()->run($command);
    $output = trim($process->output());
    $exitCode = $process->exitCode();
    if ($exitCode !== 0) {
        if (!$throwError) {
            return null;
        }
        throw new \RuntimeException($process->errorOutput(), $exitCode);
    }
    return $output;
}
function instant_remote_process(array $command, Server $server, $throwError = true, $repeat = 1)
{
    $command_string = implode("\n", $command);
    $ssh_command = generateSshCommand($server->ip, $server->user, $server->port, $command_string);
    $process =  processWithEnv()->run($ssh_command);
    $output = trim($process->output());
    $exitCode = $process->exitCode();
    if ($exitCode !== 0) {
        if ($repeat > 1) {
            ray("repeat: ", $repeat);
            Sleep::for(200)->milliseconds();
            return instant_remote_process($command, $server, $throwError, $repeat - 1);
        }
        // ray('ERROR OCCURED: ' . $process->errorOutput());
        if (!$throwError) {
            return null;
        }
        throw new \RuntimeException($process->errorOutput(), $exitCode);
    }
    return $output;
}

function decode_remote_command_output(?ApplicationDeploymentQueue $application_deployment_queue = null): Collection
{
    $application = Application::find(data_get($application_deployment_queue, 'application_id'));
    $is_debug_enabled = data_get($application, 'settings.is_debug_enabled');
    if (is_null($application_deployment_queue)) {
        return collect([]);
    }
    try {
        $decoded = json_decode(
            data_get($application_deployment_queue, 'logs'),
            associative: true,
            flags: JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $exception) {
        return collect([]);
    }
    $formatted = collect($decoded);
    if (!$is_debug_enabled) {
        $formatted = $formatted->filter(fn ($i) => $i['hidden'] === false ?? false);
    }
    $formatted = $formatted
        ->sortBy(fn ($i) => $i['order'])
        ->map(function ($i) {
            $i['timestamp'] = Carbon::parse($i['timestamp'])->format('Y-M-d H:i:s.u');
            return $i;
        });

    return $formatted;
}

function refresh_server_connection(PrivateKey $private_key)
{
    foreach ($private_key->servers as $server) {
        // Delete the old ssh mux file to force a new one to be created
        Storage::disk('ssh-mux')->delete($server->muxFilename());
        // check if user is authenticated
        // if (currentTeam()->id) {
        //     currentTeam()->privateKeys = PrivateKey::where('team_id', currentTeam()->id)->get();
        // }
    }
    removePrivateKeyFromSshAgent($server);
}

function validateServer(Server $server)
{
    try {
        refresh_server_connection($server->privateKey);
        $uptime = instant_remote_process(['uptime'], $server, false);
        if (!$uptime) {
            $server->settings->is_reachable = false;
            return [
                "uptime" => null,
                "dockerVersion" => null,
            ];
        }
        $server->settings->is_reachable = true;

        $dockerVersion = instant_remote_process(["docker version|head -2|grep -i version| awk '{print $2}'"], $server, false);
        if (!$dockerVersion) {
            $dockerVersion = null;
            return [
                "uptime" => $uptime,
                "dockerVersion" => null,
            ];
        }
        $majorDockerVersion = Str::of($dockerVersion)->before('.')->value();
        if ($majorDockerVersion <= 22) {
            $dockerVersion = null;
            $server->settings->is_usable = false;
        } else {
            $server->settings->is_usable = true;
        }
        return [
            "uptime" => $uptime,
            "dockerVersion" => $dockerVersion,
        ];
    } catch (\Throwable $e) {
        $server->settings->is_reachable = false;
        $server->settings->is_usable = false;
        throw $e;
    } finally {
        if (data_get($server, 'settings')) $server->settings->save();
    }
}

function check_server_connection(Server $server)
{
    try {
        refresh_server_connection($server->privateKey);
        instant_remote_process(['uptime'], $server);
        $server->unreachable_count = 0;
        $server->settings->is_reachable = true;
    } catch (\Throwable $e) {
        if ($server->unreachable_count == 2) {
            $server->team->notify(new NotReachable($server));
            $server->settings->is_reachable = false;
            $server->settings->save();
        } else {
            $server->unreachable_count += 1;
        }

        throw $e;
    } finally {
        $server->settings->save();
        $server->save();
    }
}

function checkRequiredCommands(Server $server)
{
    $commands = collect(["jq", "jc"]);
    foreach ($commands as $command) {
        $commandFound = instant_remote_process(["docker run --rm --privileged --net=host --pid=host --ipc=host --volume /:/host busybox chroot /host bash -c 'command -v {$command}'"], $server, false);
        if ($commandFound) {
            ray($command . ' found');
            continue;
        }
        try {
            instant_remote_process(["docker run --rm --privileged --net=host --pid=host --ipc=host --volume /:/host busybox chroot /host bash -c 'apt update && apt install -y {$command}'"], $server);
        } catch (\Throwable $e) {
            ray('could not install ' . $command);
            ray($e);
            break;
        }
        $commandFound = instant_remote_process(["docker run --rm --privileged --net=host --pid=host --ipc=host --volume /:/host busybox chroot /host bash -c 'command -v {$command}'"], $server, false);
        if ($commandFound) {
            ray($command . ' found');
            continue;
        }
        ray('could not install ' . $command);
        break;
    }
}
