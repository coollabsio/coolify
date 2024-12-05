<?php

namespace App\Actions\Docker;

use Lorisleiva\Actions\Concerns\AsAction;

class GetServerDockerImageDetails
{
    use AsAction;

    public static function run($server, $imageId)
    {
        $imageDetailsRaw = instant_remote_process(["docker inspect --type=image {$imageId}"], $server);
        $imageDetails = json_decode($imageDetailsRaw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON returned from Docker inspect', 'raw_output' => $imageDetailsRaw];
        }

        $containerCountRaw = instant_remote_process(["docker ps -q --filter ancestor={$imageId} | wc -l"], $server);
        $containerCount = intval(trim($containerCountRaw)); // Convert to an integer

        $imageDetails[0]['ContainerCount'] = $containerCount;

        return $imageDetails;
    }
}
