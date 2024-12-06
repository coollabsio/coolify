<?php

namespace App\Actions\Docker;

use Lorisleiva\Actions\Concerns\AsAction;

class DeleteAllDanglingServerDockerImages
{
    use AsAction;

    public static function run($server)
    {
        return instant_remote_process(["docker image prune --filter dangling=true -f"], $server);
    }
}
