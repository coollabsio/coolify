<?php

use App\Models\Server;
use Illuminate\Support\Collection;

function format_docker_command_output_to_json($rawOutput): Collection
{
    $outputLines = explode(PHP_EOL, $rawOutput);

    return collect($outputLines)
        ->reject(fn($line) => empty($line))
        ->map(fn($outputLine) => json_decode($outputLine, true, flags: JSON_THROW_ON_ERROR));
}

function format_docker_labels_to_json($rawOutput): Collection
{
    $outputLines = explode(PHP_EOL, $rawOutput);

    return collect($outputLines)
        ->reject(fn($line) => empty($line))
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

function get_container_status(Server $server, string $container_id, bool $all_data = false, bool $throwError = false)
{
    $container = instant_remote_process(["docker inspect --format '{{json .}}' {$container_id}"], $server, $throwError);
    if (!$container) {
        return 'exited';
    }
    $container = format_docker_command_output_to_json($container);
    if ($all_data) {
        return $container[0];
    }
    return $container[0]['State']['Status'];
}

function generate_container_name(string $uuid, int $pull_request_id = 0)
{
    if ($pull_request_id !== 0) {
        return $uuid . '-pr-' . $pull_request_id;
    } else {
        return $uuid;
    }
}
