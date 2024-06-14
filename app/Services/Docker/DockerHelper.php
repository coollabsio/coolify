<?php

namespace App\Services\Docker;

use App\Models\Server;
use App\Services\Docker\Output\DockerNetworkContainerInstanceOutput;
use App\Services\Docker\Output\DockerNetworkContainerOutput;
use App\Services\Remote\InstantRemoteProcess;
use App\Services\Remote\InstantRemoteProcessFactory;
use Illuminate\Support\Collection;

class DockerHelper
{
    public static function getContainersInNetwork(Server $server, string $networkName): DockerNetworkContainerOutput
    {
        $command = "docker network inspect $networkName --format='{{json .Containers}}'";

        $factory = new InstantRemoteProcessFactory($server);

        $output = $factory->getCommandOutput([$command]);

        $process = new InstantRemoteProcess($server, $output);

        $result = $process->getOutput();

        $containersParsed = self::formatDockerOutputToJson($result);

        // TODO: Check if we can remove this.
        $allContainers = $containersParsed[0];
        $network  = new DockerNetworkContainerOutput();

        foreach($allContainers as $id => $info) {
            $endpoint = new DockerNetworkContainerInstanceOutput(
                $id,
                $info["Name"],
                $info["EndpointID"],
                $info["MacAddress"],
                $info["IPv4Address"],
                $info["IPv6Address"]
            );

            $network->addEndpoint($endpoint);
        }

        return $network;
    }

    /**
     * @param string $output
     * @see format_docker_command_output_to_json
     * @return Collection
     */
    private static function formatDockerOutputToJson(string $output): Collection {
        $outputLines = explode(PHP_EOL, $output);
        if (count($outputLines) === 1) {
            $outputLines = collect($outputLines[0]);
        } else {
            $outputLines = collect($outputLines);
        }

        return $outputLines
            ->reject(fn ($line) => empty($line))
            ->map(fn ($outputLine) => json_decode($outputLine, true, flags: JSON_THROW_ON_ERROR));
    }
}
