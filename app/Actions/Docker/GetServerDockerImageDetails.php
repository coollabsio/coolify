<?php

namespace App\Actions\Docker;

use Lorisleiva\Actions\Concerns\AsAction;

class GetServerDockerImageDetails
{
    use AsAction;

    public static function run($server, $imageId)
    {
        $result = instant_remote_process(["docker inspect --format 'json' --type=image {$imageId}"], $server);

        return json_decode($result, true);
    }
}
