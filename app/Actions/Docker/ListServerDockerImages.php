<?php

namespace App\Actions\Docker;

use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListServerDockerImages
{
    use AsAction;

    public Collection $server;

    public static function run($server)
    {
        $commandForContainers = "curl --unix-socket /var/run/docker.sock http://localhost/containers/json?all=true";
        $containers = json_decode(instant_remote_process([$commandForContainers], $server), true);

        $runningImages = array();
        foreach ($containers as $container) {
            $runningImages[$container['ImageID']][] = true;
        }

        $command = "curl --unix-socket /var/run/docker.sock http://localhost/images/json";
        $imagesJson = json_decode(instant_remote_process([$command], $server), true);

        return $imagesJson;

        $images = [];

        foreach ($imagesJson as $image) {
            $isRunning = key_exists($image['Id'], $runningImages);

            foreach ($image['RepoTags'] as $tag) {
                $imageCopy = $image;
                $imageCopy["RepoTags"] = $tag;

                if ($isRunning){
                    $imageCopy["Status"] = 'in use';
                }else{
                    $imageCopy["Status"] = 'unused';
                }

                if ($image['RepoTags'] == []){
                    $imageCopy["Dangling"] = true;
                }else{
                    $imageCopy["Dangling"] = false;
                }

                array_push($images, $imageCopy);
            }
        }

        return $images;
    }
}
