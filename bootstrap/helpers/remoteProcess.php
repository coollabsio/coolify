<?php

use App\Actions\CoolifyTask\PrepareCoolifyTask;
use App\Data\CoolifyTaskArgs;
use App\Enums\ActivityTypes;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity;


function remote_process(
    Collection|array $command,
    Server $server,
    ?string $type = null,
    ?string $type_uuid = null,
    ?Model $model = null,
    bool $ignore_errors = false,
    $callEventOnFinish = null,
    $callEventData = null
): Activity {
    $type = $type ?? ActivityTypes::INLINE->value;
    $command = $command instanceof Collection ? $command->toArray() : $command;
    
    if ($server->isNonRoot()) {
        $command = parseCommandsByLineForSudo(collect($command), $server);
    }
    
    $command_string = implode("\n", $command);
    
    if (auth()->user()) {
        $teams = auth()->user()->teams->pluck('id');
        if (!$teams->contains($server->team_id) && !$teams->contains(0)) {
            throw new \Exception('User is not part of the team that owns this server');
        }
    }

    return resolve(PrepareCoolifyTask::class, [
        'remoteProcessArgs' => new CoolifyTaskArgs(
            server_uuid: $server->uuid,
            command: $command_string,
            type: $type,
            type_uuid: $type_uuid,
            model: $model,
            ignore_errors: $ignore_errors,
            call_event_on_finish: $callEventOnFinish,
            call_event_data: $callEventData,
        ),
    ])();
}

function server_ssh_configuration(Server $server)
{
    $sshKeyLocation = $server->privateKey->getKeyLocation();
    ray($sshKeyLocation);
    $mux_filename = '/var/www/html/storage/app/ssh/mux/'.$server->muxFilename();

    return [
        'sshKeyLocation' => $sshKeyLocation,
        'muxFilename' => $mux_filename,
    ];
}

function generateScpCommand(Server $server, string $source, string $dest)
{
    $sshConfig = server_ssh_configuration($server);
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
        ensureMultiplexedConnection($server);
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

function instant_scp(string $source, string $dest, Server $server, $throwError = true)
{
    $timeout = config('constants.ssh.command_timeout');
    $scp_command = generateScpCommand($server, $source, $dest);
    $process = Process::timeout($timeout)->run($scp_command);
    $output = trim($process->output());
    $exitCode = $process->exitCode();
    if ($exitCode !== 0) {
        if (! $throwError) {
            return null;
        }

        return excludeCertainErrors($process->errorOutput(), $exitCode);
    }
    if ($output === 'null') {
        $output = null;
    }

    return $output;
}

function generateSshCommand(Server $server, string $command)
{
    if ($server->settings->force_disabled) {
        throw new \RuntimeException('Server is disabled.');
    }

    $sshConfig = server_ssh_configuration($server);
    $sshKeyLocation = $sshConfig['sshKeyLocation'];
    $muxSocket = $sshConfig['muxFilename'];

    $timeout = config('constants.ssh.command_timeout');
    $connectionTimeout = config('constants.ssh.connection_timeout');
    $serverInterval = config('constants.ssh.server_interval');
    $muxPersistTime = config('constants.ssh.mux_persist_time');
    $muxEnabled = config('constants.ssh.mux_enabled') && !config('coolify.is_windows_docker_desktop');

    $ssh_command = "timeout $timeout ssh ";

    ray('SSH Multiplexing Enabled:', $muxEnabled)->blue();

    if ($muxEnabled) {
        $ssh_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
        ensureMultiplexedConnection($server);
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

function ensureMultiplexedConnection(Server $server)
{
    static $ensuredConnections = [];

    $sshConfig = server_ssh_configuration($server);
    $muxSocket = $sshConfig['muxFilename'];

    if (isset($ensuredConnections[$server->id]) && !shouldResetMultiplexedConnection($server)) {
        return;
    }

    $checkCommand = "ssh -O check -o ControlPath=$muxSocket {$server->user}@{$server->ip}";
    $process = Process::run($checkCommand);

    if ($process->exitCode() === 0) {
        $ensuredConnections[$server->id] = [
            'timestamp' => now(),
            'muxSocket' => $muxSocket,
        ];
        return;
    }

    $sshKeyLocation = $sshConfig['sshKeyLocation'];
    $connectionTimeout = config('constants.ssh.connection_timeout');
    $serverInterval = config('constants.ssh.server_interval');
    $muxPersistTime = config('constants.ssh.mux_persist_time');

    $establishCommand = "ssh -fNM -o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} "
        ."-i {$sshKeyLocation} "
        .'-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
        .'-o PasswordAuthentication=no '
        ."-o ConnectTimeout=$connectionTimeout "
        ."-o ServerAliveInterval=$serverInterval "
        .'-o RequestTTY=no '
        .'-o LogLevel=ERROR '
        ."-p {$server->port} "
        ."{$server->user}@{$server->ip}";

    $establishProcess = Process::run($establishCommand);

    if ($establishProcess->exitCode() !== 0) {
        throw new \RuntimeException('Failed to establish multiplexed connection: '.$establishProcess->errorOutput());
    }

    $ensuredConnections[$server->id] = [
        'timestamp' => now(),
        'muxSocket' => $muxSocket,
    ];
}

function shouldResetMultiplexedConnection(Server $server)
{
    if (! (config('constants.ssh.mux_enabled') && config('coolify.is_windows_docker_desktop') == false)) {
        return false;
    }

    static $ensuredConnections = [];

    if (! isset($ensuredConnections[$server->id])) {
        return true;
    }

    $lastEnsured = $ensuredConnections[$server->id]['timestamp'];
    $muxPersistTime = config('constants.ssh.mux_persist_time');
    $resetInterval = strtotime($muxPersistTime) - time();

    return $lastEnsured->addSeconds($resetInterval)->isPast();
}

function resetMultiplexedConnection(Server $server)
{
    if (! (config('constants.ssh.mux_enabled') && config('coolify.is_windows_docker_desktop') == false)) {
        return;
    }

    static $ensuredConnections = [];

    if (isset($ensuredConnections[$server->id])) {
        $muxSocket = $ensuredConnections[$server->id]['muxSocket'];
        $closeCommand = "ssh -O exit -o ControlPath=$muxSocket {$server->user}@{$server->ip}";
        Process::run($closeCommand);
        unset($ensuredConnections[$server->id]);
    }
}

function instant_remote_process(Collection|array $command, Server $server, bool $throwError = true, bool $no_sudo = false): ?string
{
    static $processCount = 0;
    $processCount++;

    $timeout = config('constants.ssh.command_timeout');
    if ($command instanceof Collection) {
        $command = $command->toArray();
    }
    if ($server->isNonRoot() && ! $no_sudo) {
        $command = parseCommandsByLineForSudo(collect($command), $server);
    }
    $command_string = implode("\n", $command);

    $start_time = microtime(true);
    $sshCommand = generateSshCommand($server, $command_string);
    $process = Process::timeout($timeout)->run($sshCommand);
    $end_time = microtime(true);

    // $execution_time = ($end_time - $start_time) * 1000; // Convert to milliseconds
    // ray('SSH command execution time:', $execution_time.' ms')->orange();

    $output = trim($process->output());
    $exitCode = $process->exitCode();

    if ($exitCode !== 0) {
        if (! $throwError) {
            return null;
        }

        return excludeCertainErrors($process->errorOutput(), $exitCode);
    }
    if ($output === 'null') {
        $output = null;
    }

    return $output;
}

function excludeCertainErrors(string $errorOutput, ?int $exitCode = null)
{
    $ignoredErrors = collect([
        'Permission denied (publickey',
        'Could not resolve hostname',
    ]);
    $ignored = false;
    foreach ($ignoredErrors as $ignoredError) {
        if (Str::contains($errorOutput, $ignoredError)) {
            $ignored = true;
            break;
        }
    }
    if ($ignored) {
        // TODO: Create new exception and disable in sentry
        throw new \RuntimeException($errorOutput, $exitCode);
    }
    throw new \RuntimeException($errorOutput, $exitCode);
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
    $seenCommands = collect();
    $formatted = collect($decoded);
    if (! $is_debug_enabled) {
        $formatted = $formatted->filter(fn ($i) => $i['hidden'] === false ?? false);
    }
    $formatted = $formatted
        ->sortBy(fn ($i) => data_get($i, 'order'))
        ->map(function ($i) {
            data_set($i, 'timestamp', Carbon::parse(data_get($i, 'timestamp'))->format('Y-M-d H:i:s.u'));

            return $i;
        })
        ->reduce(function ($deploymentLogLines, $logItem) use ($seenCommands) {
            $command = data_get($logItem, 'command');
            $isStderr = data_get($logItem, 'type') === 'stderr';
            $isNewCommand = ! is_null($command) && ! $seenCommands->first(function ($seenCommand) use ($logItem) {
                return data_get($seenCommand, 'command') === data_get($logItem, 'command') && data_get($seenCommand, 'batch') === data_get($logItem, 'batch');
            });

            if ($isNewCommand) {
                $deploymentLogLines->push([
                    'line' => $command,
                    'timestamp' => data_get($logItem, 'timestamp'),
                    'stderr' => $isStderr,
                    'hidden' => data_get($logItem, 'hidden'),
                    'command' => true,
                ]);

                $seenCommands->push([
                    'command' => $command,
                    'batch' => data_get($logItem, 'batch'),
                ]);
            }

            $lines = explode(PHP_EOL, data_get($logItem, 'output'));

            foreach ($lines as $line) {
                $deploymentLogLines->push([
                    'line' => $line,
                    'timestamp' => data_get($logItem, 'timestamp'),
                    'stderr' => $isStderr,
                    'hidden' => data_get($logItem, 'hidden'),
                ]);
            }

            return $deploymentLogLines;
        }, collect());

    return $formatted;
}

function remove_iip($text)
{
    $text = preg_replace('/x-access-token:.*?(?=@)/', 'x-access-token:'.REDACTED, $text);

    return preg_replace('/\x1b\[[0-9;]*m/', '', $text);
}

function remove_mux_and_private_key(Server $server)
{
    $sshConfig = server_ssh_configuration($server);
    $privateKeyLocation = $sshConfig['sshKeyLocation'];
    $muxFilename = basename($sshConfig['muxFilename']);
    

    $closeCommand = "ssh -O exit -o ControlPath=/var/www/html/storage/app/ssh/mux/{$muxFilename} {$server->user}@{$server->ip}";
    Process::run($closeCommand);

    Storage::disk('ssh-mux')->delete($muxFilename);
    Storage::disk('ssh-keys')->delete($privateKeyLocation);
}

function refresh_server_connection(?PrivateKey $private_key = null)
{
    if (is_null($private_key)) {
        return;
    }
    foreach ($private_key->servers as $server) {
        $sshConfig = server_ssh_configuration($server);
        $muxFilename = basename($sshConfig['muxFilename']);
        $closeCommand = "ssh -O exit -o ControlPath=/var/www/html/storage/app/ssh/mux/{$muxFilename} {$server->user}@{$server->ip}";
        Process::run($closeCommand);
        Storage::disk('ssh-mux')->delete($muxFilename);
    }
}

function checkRequiredCommands(Server $server)
{
    $commands = collect(['jq', 'jc']);
    foreach ($commands as $command) {
        $commandFound = instant_remote_process(["docker run --rm --privileged --net=host --pid=host --ipc=host --volume /:/host busybox chroot /host bash -c 'command -v {$command}'"], $server, false);
        if ($commandFound) {
            continue;
        }
        try {
            instant_remote_process(["docker run --rm --privileged --net=host --pid=host --ipc=host --volume /:/host busybox chroot /host bash -c 'apt update && apt install -y {$command}'"], $server);
        } catch (\Throwable $e) {
            break;
        }
        $commandFound = instant_remote_process(["docker run --rm --privileged --net=host --pid=host --ipc=host --volume /:/host busybox chroot /host bash -c 'command -v {$command}'"], $server, false);
        if ($commandFound) {
            continue;
        }
        break;
    }
}
