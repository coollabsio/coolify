<?php

namespace App\Actions\Docker;

use Lorisleiva\Actions\Concerns\AsAction;

class DeleteServerDockerImage
{
    use AsAction;

    public static function run($server, $imageId)
    {

        $imageDetails = GetServerDockerImageDetails::run($server, $imageId);

        if ($imageDetails['ContainerCount'] != 0){
            return response()->json(['error' => "Cannot delete image because it is used"], 403);
        }

        return instant_remote_process(["docker image rmi {$imageId} -f"], $server);
    }
}
