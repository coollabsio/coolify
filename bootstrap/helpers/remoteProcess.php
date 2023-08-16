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

/**
 * Run a Remote Process, which SSH's asynchronously into a machine to run the command(s).
 * @TODO Change 'root' to 'coolify' when it's able to run Docker commands without sudo
 *
 */
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
            ignore_errors: $ignore_errors
        ),
    ])();
}

function get_private_key_for_server(Server $server)
{
    $temp_file = "id.root@{$server->ip}";
    return '/var/www/html/storage/app/ssh/keys/' . $temp_file;
}

function save_private_key_for_server(Server $server)
{
    if (data_get($server, 'privateKey.private_key') === null) {
        $server->settings->is_reachable = false;
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
            if (auth()?->user()?->currentTeam()->id) {
                auth()->user()->currentTeam()->privateKeys = PrivateKey::where('team_id', auth()->user()->currentTeam()->id)->get();
            }
    }
}

function validateServer(Server $server)
{
    try {
        refresh_server_connection($server->privateKey);
        $uptime = instant_remote_process(['uptime'], $server);
        if (!$uptime) {
            $uptime = 'Server not reachable.';
            throw new \Exception('Server not reachable.');
        }
        $server->settings->is_reachable = true;

        $dockerVersion = instant_remote_process(['docker version|head -2|grep -i version'], $server, false);
        if (!$dockerVersion) {
            $dockerVersion = 'Not installed.';
            throw new \Exception('Docker not installed.');
        }
        $server->settings->is_usable = true;
        return [
            "uptime" => $uptime,
            "dockerVersion" => $dockerVersion,
        ];
    } catch (\Exception $e) {
        $server->settings->is_reachable = false;
        $server->settings->is_usable = false;
        throw $e;
    } finally {
        $server->settings->save();
    }
}

function check_server_connection(Server $server) {
    try {
        refresh_server_connection($server->privateKey);
        instant_remote_process(['uptime'], $server);
        $server->unreachable_count = 0;
        $server->settings->is_reachable = true;
    } catch (\Exception $e) {
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
