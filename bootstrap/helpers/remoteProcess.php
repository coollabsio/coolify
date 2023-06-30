<?php

use App\Actions\CoolifyTask\PrepareCoolifyTask;
use App\Data\CoolifyTaskArgs;
use App\Enums\ActivityTypes;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJobNew;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Server;
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
    string $type = ActivityTypes::INLINE->value,
    ?string $type_uuid = null,
    ?Model  $model = null,
    bool    $ignore_errors = false
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

function decode_remote_command_output(?ApplicationDeploymentQueue $application_deployment_queue = null): Collection
{
    $application = Application::find(data_get($application_deployment_queue, 'application_id'));
    $is_debug_enabled = data_get($application, 'settings.is_debug_enabled');
    if (is_null($application_deployment_queue)) {
        return collect([]);
    }
    try {
        $decoded = json_decode(
            data_get($application_deployment_queue, 'log'),
            associative: true,
            flags: JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $exception) {
        return collect([]);
    }
    $formatted = collect($decoded);
    if (!$is_debug_enabled) {

        $formatted = $formatted->filter(fn ($i) => $i['show_in_output'] ?? true);
    }
    $formatted = $formatted->sortBy(fn ($i) => $i['order'])
        ->map(function ($i) {
            $i['timestamp'] = Carbon::parse($i['timestamp'])->format('Y-M-d H:i:s.u');
            return $i;
        });

    return $formatted;
}
function execute_remote_command(array|Collection $commands, Server $server, ApplicationDeploymentQueue $queue, bool $show_in_output = true, bool $ignore_errors = false)
{
    if ($commands instanceof Collection) {
        $commandsText = $commands;
    } else {
        $commandsText = collect($commands);
    }
    $ip = data_get($server, 'ip');
    $user = data_get($server, 'user');
    $port = data_get($server, 'port');
    $private_key_location = get_private_key_for_server($server);
    $commandsText->each(function ($command) use ($queue, $private_key_location, $ip, $user, $port, $show_in_output, $ignore_errors) {
        $remote_command = generate_ssh_command($private_key_location, $ip, $user, $port, $command);
        $process = Process::timeout(3600)->idleTimeout(3600)->start($remote_command, function (string $type, string $output) use ($queue, $command, $show_in_output) {
            $new_log_entry = [
                'command' => $command,
                'output' => $output,
                'type' => $type === 'err' ? 'stderr' : 'stdout',
                'timestamp' => Carbon::now('UTC'),
                'show_in_output' => $show_in_output,
            ];

            if (!$queue->log) {
                $new_log_entry['order'] = 1;
            } else {
                $previous_logs = json_decode($queue->log, associative: true, flags: JSON_THROW_ON_ERROR);
                $new_log_entry['order'] = count($previous_logs) + 1;
            }

            $previous_logs[] = $new_log_entry;
            $queue->log = json_encode($previous_logs, flags: JSON_THROW_ON_ERROR);;
            $queue->save();
        });
        $queue->update([
            'current_process_id' => $process->id(),
        ]);

        $process_result = $process->wait();
        if ($process_result->exitCode() !== 0) {
            if (!$ignore_errors) {
                $status = ApplicationDeploymentStatus::FAILED->value;
                $queue->status = $status;
                $queue->save();
                throw new \RuntimeException($process_result->errorOutput());
            }
        }
    });
}
