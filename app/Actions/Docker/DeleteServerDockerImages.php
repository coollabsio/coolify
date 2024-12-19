<?php

namespace App\Actions\Docker;

use Lorisleiva\Actions\Concerns\AsAction;

class DeleteServerDockerImages
{
    use AsAction;

    public static function run($server, $ids)
    {
        $idsForCommand = implode(' ', $ids);
        return instant_remote_process(["docker rmi $idsForCommand -f"], $server);
    }
}
