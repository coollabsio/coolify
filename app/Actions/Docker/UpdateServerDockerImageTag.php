<?php

namespace App\Actions\Docker;

use Lorisleiva\Actions\Concerns\AsAction;

class UpdateServerDockerImageTag
{
    use AsAction;

    public static function run($server, $imageId, $imageRepo, $tagName)
    {

        //$imageDetails = GetServerDockerImageDetails::run($server, $imageId);
        //$imageRepo = explode(':', $imageDetails['RepoTags'][0])[0];
        // dd($imageId, $imageRepo, $tagName);
        return instant_remote_process(["docker tag {$imageId} {$imageRepo}:{$tagName}"], $server);
    }
}
