<?php

use App\Models\Server;
use Illuminate\Support\Collection;

function format_docker_command_output_to_json($rawOutput): Collection
{
    $outputLines = explode(PHP_EOL, $rawOutput);

    return collect($outputLines)
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

function get_container_status(Server $server, string $container_id, bool $throwError = false)
{
    $container = instant_remote_process(["docker inspect --format '{{json .State}}' {$container_id}"], $server, $throwError);
    if (!$container) {
        return 'exited';
    }
    $container = format_docker_command_output_to_json($container);
    return $container[0]['Status'];
}

function generate_container_name(string $uuid, int|null $pull_request_id = null)
{
    if ($pull_request_id) {
        return $uuid . '_pr_' . $pull_request_id;
    } else {
        return $uuid;
    }
}
