<?php

use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

function getCurrentApplicationContainerStatus(Server $server, int $id): Collection {
    $containers = instant_remote_process(["docker ps -a --filter='label=coolify.applicationId={$id}' --format '{{json .}}' "], $server);
    if (!$containers) {
        return collect([]);
    }
    return format_docker_command_output_to_json($containers);
}

function format_docker_command_output_to_json($rawOutput): Collection
{
    $outputLines = explode(PHP_EOL, $rawOutput);
    if (count($outputLines) === 1) {
        $outputLines = collect($outputLines[0]);
    } else {
        $outputLines = collect($outputLines);
    }
    return $outputLines
        ->reject(fn ($line) => empty($line))
        ->map(fn ($outputLine) => json_decode($outputLine, true, flags: JSON_THROW_ON_ERROR));
}

function format_docker_labels_to_json($rawOutput): Collection
{
    $outputLines = explode(PHP_EOL, $rawOutput);

    return collect($outputLines)
        ->reject(fn ($line) => empty($line))
        ->map(function ($outputLine) {
            $outputArray = explode(',', $outputLine);
            return collect($outputArray)
                ->map(function ($outputLine) {
                    return explode('=', $outputLine);
                })
                ->mapWithKeys(function ($outputLine) {
                    return [$outputLine[0] => $outputLine[1]];
                });
        })[0];
}

function format_docker_envs_to_json($rawOutput)
{
    try {
        $outputLines = json_decode($rawOutput, true, flags: JSON_THROW_ON_ERROR);
        return collect(data_get($outputLines[0], 'Config.Env', []))->mapWithKeys(function ($env) {
            $env = explode('=', $env);
            return [$env[0] => $env[1]];
        });
    } catch (\Throwable $th) {
        return collect([]);
    }
}

function getApplicationContainerStatus(Application $application) {
    $server = data_get($application,'destination.server');
    $id = $application->id;
    if (!$server) {
        return 'exited';
    }
    $containers = getCurrentApplicationContainerStatus($server, $id);
    if ($containers->count() > 0) {
        $status = data_get($containers[0], 'State', 'exited');
        return $status;
    }
    return 'exited';
}
function getContainerStatus(Server $server, string $container_id, bool $all_data = false, bool $throwError = false)
{
    // check_server_connection($server);
    $container = instant_remote_process(["docker inspect --format '{{json .}}' {$container_id}"], $server, $throwError);
    if (!$container) {
        return 'exited';
    }
    $container = format_docker_command_output_to_json($container);
    if ($all_data) {
        return $container[0];
    }
    return data_get($container[0], 'State.Status', 'exited');
}

function generateApplicationContainerName(string $uuid, int $pull_request_id = 0)
{
    $now = now()->format('Hisu');
    if ($pull_request_id !== 0 && $pull_request_id !== null) {
        return $uuid . '-pr-' . $pull_request_id . '-' . $now;
    } else {
        return $uuid . '-' . $now;
    }
}
function get_port_from_dockerfile($dockerfile): int
{
    $dockerfile_array = explode("\n", $dockerfile);
    $found_exposed_port = null;
    foreach ($dockerfile_array as $line) {
        $line_str = Str::of($line)->trim();
        if ($line_str->startsWith('EXPOSE')) {
            $found_exposed_port = $line_str->replace('EXPOSE', '')->trim();
            break;
        }
    }
    if ($found_exposed_port) {
        return (int)$found_exposed_port->value();
    }
    return 80;
}
