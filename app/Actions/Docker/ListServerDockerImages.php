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

            case 'unused':
                return array_values(array_filter(self::get_all_docker_images($server), function ($image) {
                    return ($image['ContainerCount'] == 0 && $image['Tag'] !== '<none>');
                }));

            case 'used':
                return array_values(array_filter(self::get_all_docker_images($server), function ($image) {
                    return $image['ContainerCount'] > 0;
            }));

            case 'all':
                return self::get_all_docker_images($server);
        }

        return self::get_all_docker_images($server);
    }
    private static function get_all_docker_images($server)
    {
        $allImagesOutput = format_docker_command_output_to_json(instant_remote_process(["docker images --format '{{json .}}'"], $server))->all();

        $usedContainersOutput = instant_remote_process(["docker ps -a --format '{{.Image}}\t{{.ID}}'"], $server);
        $usedContainersArray = array_filter(explode("\n", $usedContainersOutput));

        $imageCountMap = [];

        // Process each container to map image count
        foreach ($usedContainersArray as $line) {
            [$image, $containerId] = explode("\t", $line);

            if (strpos($image, ":") === false) {
                $image = "{$image}:latest";
            }

            if (!isset($imageCountMap[$image])) {
                $imageCountMap[$image] = 0;
            }

            $imageCountMap[$image]++;
        }

        // Now, map the container count to the images
        foreach ($allImagesOutput as &$image) {
            if ($image['Tag'] !== 'latest' &&  $image['Tag'] !== '<none>'){
                $fullImageName = "{$image['Repository']}:{$image['Tag']}";
            } elseif ($image['Repository'] === '<none>') {
                $fullImageName = $image['ID'];
            } else {
                $fullImageName = "{$image['Repository']}:latest";
            }

            $image['ContainerCount'] = $imageCountMap[$fullImageName] ?? 0;
        }

        unset($image);

        return $allImagesOutput;
    }

}
