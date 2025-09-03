<?php

use App\Actions\CoolifyTask\PrepareCoolifyTask;
use App\Data\CoolifyTaskArgs;
use App\Enums\ActivityTypes;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
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

    if (Auth::check()) {
        $teams = Auth::user()->teams->pluck('id');
        if (! $teams->contains($server->team_id) && ! $teams->contains(0)) {
            throw new \Exception('User is not part of the team that owns this server');
        }
    }

    SshMultiplexingHelper::ensureMultiplexedConnection($server);

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

function instant_scp(string $source, string $dest, Server $server, $throwError = true)
{
    $scp_command = SshMultiplexingHelper::generateScpCommand($server, $source, $dest);
    $process = Process::timeout(config('constants.ssh.command_timeout'))->run($scp_command);
    $output = trim($process->output());
    $exitCode = $process->exitCode();
    if ($exitCode !== 0) {
        return $throwError ? excludeCertainErrors($process->errorOutput(), $exitCode) : null;
    }

    return $output === 'null' ? null : $output;
}

function instant_remote_process_with_timeout(Collection|array $command, Server $server, bool $throwError = true, bool $no_sudo = false): ?string
{
    $command = $command instanceof Collection ? $command->toArray() : $command;
    if ($server->isNonRoot() && ! $no_sudo) {
        $command = parseCommandsByLineForSudo(collect($command), $server);
    }
    $command_string = implode("\n", $command);

    // $start_time = microtime(true);
    $sshCommand = SshMultiplexingHelper::generateSshCommand($server, $command_string);
    $process = Process::timeout(30)->run($sshCommand);
    // $end_time = microtime(true);

    // $execution_time = ($end_time - $start_time) * 1000; // Convert to milliseconds
    // ray('SSH command execution time:', $execution_time.' ms')->orange();

    $output = trim($process->output());
    $exitCode = $process->exitCode();

    if ($exitCode !== 0) {
        return $throwError ? excludeCertainErrors($process->errorOutput(), $exitCode) : null;
    }

    // Sanitize output to ensure valid UTF-8 encoding
    $output = $output === 'null' ? null : sanitize_utf8_text($output);

    return $output;
}

function instant_remote_process(Collection|array $command, Server $server, bool $throwError = true, bool $no_sudo = false): ?string
{
    $command = $command instanceof Collection ? $command->toArray() : $command;
    if ($server->isNonRoot() && ! $no_sudo) {
        $command = parseCommandsByLineForSudo(collect($command), $server);
    }
    $command_string = implode("\n", $command);

    // $start_time = microtime(true);
    $sshCommand = SshMultiplexingHelper::generateSshCommand($server, $command_string);
    $process = Process::timeout(config('constants.ssh.command_timeout'))->run($sshCommand);
    // $end_time = microtime(true);

    // $execution_time = ($end_time - $start_time) * 1000; // Convert to milliseconds
    // ray('SSH command execution time:', $execution_time.' ms')->orange();

    $output = trim($process->output());
    $exitCode = $process->exitCode();

    if ($exitCode !== 0) {
        return $throwError ? excludeCertainErrors($process->errorOutput(), $exitCode) : null;
    }

    // Sanitize output to ensure valid UTF-8 encoding
    $output = $output === 'null' ? null : sanitize_utf8_text($output);

    return $output;
}

function excludeCertainErrors(string $errorOutput, ?int $exitCode = null)
{
    $ignoredErrors = collect([
        'Permission denied (publickey',
        'Could not resolve hostname',
    ]);
    $ignored = $ignoredErrors->contains(fn ($error) => Str::contains($errorOutput, $error));
    if ($ignored) {
        // TODO: Create new exception and disable in sentry
        throw new \RuntimeException($errorOutput, $exitCode);
    }
    throw new \RuntimeException($errorOutput, $exitCode);
}

function decode_remote_command_output(?ApplicationDeploymentQueue $application_deployment_queue = null): Collection
{
    if (is_null($application_deployment_queue)) {
        return collect([]);
    }
    $application = Application::find(data_get($application_deployment_queue, 'application_id'));
    $is_debug_enabled = data_get($application, 'settings.is_debug_enabled');

    $logs = data_get($application_deployment_queue, 'logs');
    if (empty($logs)) {
        return collect([]);
    }

    try {
        $decoded = json_decode(
            $logs,
            associative: true,
            flags: JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $e) {
        // If JSON decoding fails, try to clean up the logs and retry
        try {
            // Ensure valid UTF-8 encoding
            $cleaned_logs = sanitize_utf8_text($logs);
            $decoded = json_decode(
                $cleaned_logs,
                associative: true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            // If it still fails, return empty collection to prevent crashes
            return collect([]);
        }
    }

    if (! is_array($decoded)) {
        return collect([]);
    }

    $seenCommands = collect();
    $formatted = collect($decoded);
    if (! $is_debug_enabled) {
        $formatted = $formatted->filter(fn ($i) => $i['hidden'] === false ?? false);
    }

    return $formatted
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
}

function remove_iip($text)
{
    // Ensure the input is valid UTF-8 before processing
    $text = sanitize_utf8_text($text);

    $text = preg_replace('/x-access-token:.*?(?=@)/', 'x-access-token:'.REDACTED, $text);

    return preg_replace('/\x1b\[[0-9;]*m/', '', $text);
}

/**
 * Sanitizes text to ensure it contains valid UTF-8 encoding.
 *
 * This function is crucial for preventing "Malformed UTF-8 characters" errors
 * that can occur when Docker build output contains binary data mixed with text,
 * especially during image processing or builds with many assets.
 *
 * @param  string|null  $text  The text to sanitize
 * @return string Valid UTF-8 encoded text
 */
function sanitize_utf8_text(?string $text): string
{
    if (empty($text)) {
        return '';
    }

    // Convert to UTF-8, replacing invalid sequences
    $sanitized = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

    // Additional fallback: use SUBSTITUTE flag to replace invalid sequences with substitution character
    if (! mb_check_encoding($sanitized, 'UTF-8')) {
        $sanitized = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text, mb_detect_order(), true) ?: 'UTF-8');
    }

    return $sanitized;
}

function refresh_server_connection(?PrivateKey $private_key = null)
{
    if (is_null($private_key)) {
        return;
    }
    foreach ($private_key->servers as $server) {
        SshMultiplexingHelper::removeMuxFile($server);
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
        } catch (\Throwable) {
            break;
        }
        $commandFound = instant_remote_process(["docker run --rm --privileged --net=host --pid=host --ipc=host --volume /:/host busybox chroot /host bash -c 'command -v {$command}'"], $server, false);
        if (! $commandFound) {
            break;
        }
    }
}
