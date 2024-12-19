<?php

namespace App\Actions\Docker;

use Lorisleiva\Actions\Concerns\AsAction;

class UpdateServerDockerImageTag
{
    use AsAction;

    public static function run($server, $imageId, $tagName)
    {
        $imageDetails = GetServerDockerImageDetails::run($server, $imageId);

        return instant_remote_process(["docker tag {$imageId} {$imageDetails['Repository']}:{$tagName}"], $server);
    }
}
