<?php

namespace App\Actions\Docker;

use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListServerDockerImages
{
    use AsAction;

    public Collection $server;

    public static function run($server, $filter)
    {

        switch ($filter) {
            case 'dangling':
                return format_docker_command_output_to_json(instant_remote_process(["docker images --filter 'dangling=true' --format '{{json .}}'"], $server));

            default:
                return format_docker_command_output_to_json(instant_remote_process(["docker images -a --format '{{json .}}'"], $server));
        }
    }

}
