<?php

namespace App\Services\Docker;

use App\Models\Server;
use App\Services\Docker\Output\DockerNetworkContainerInstanceOutput;
use App\Services\Docker\Output\DockerNetworkContainerOutput;
use App\Services\Remote\Provider\RemoteProcessProvider;
use App\Services\Remote\RemoteProcessManager;
use App\Services\Shared\Models\ExecutedProcessResult;
use Illuminate\Support\Collection;

class DockerHelper
{
    private RemoteProcessManager $remoteProcessManager;

    public function __construct(Server $server, RemoteProcessProvider $processProvider)
    {
        $this->remoteProcessManager = $processProvider->forServer($server);
    }

    public function getContainersInNetwork(string $networkName): DockerNetworkContainerOutput
    {
        $command = "docker network inspect $networkName --format='{{json .Containers}}'";

        $result = $this->remoteProcessManager->execute($command);
        $containersParsed = self::formatDockerOutputToJson($result->result);

        // TODO: Check if we can remove this.
        $allContainers = $containersParsed[0];
        $network = new DockerNetworkContainerOutput();

        foreach ($allContainers as $id => $info) {
            $endpoint = new DockerNetworkContainerInstanceOutput(
                $id,
                $info['Name'],
                $info['EndpointID'],
                $info['MacAddress'],
                $info['IPv4Address'],
                $info['IPv6Address']
            );

            $network->addEndpoint($endpoint);
        }

        return $network;
    }

    public function createNetwork(string $networkName): ExecutedProcessResult
    {
        $command = "docker network create $networkName";

        return $this->remoteProcessManager->execute($command);
    }

    public function destroyNetwork(string $networkName): ExecutedProcessResult
    {
        $command = "docker network rm $networkName";

        return $this->remoteProcessManager->execute($command);
    }

    public function removeContainer(string $container): ExecutedProcessResult
    {
        $command = "docker rm -f {$container} >/dev/null 2>&1";

        return $this->remoteProcessManager->execute($command);
    }

    /**
     * @see format_docker_command_output_to_json
     */
    private static function formatDockerOutputToJson(string $output): Collection
    {
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

    public function getContainersForCoolifyLabelId(int $id): Collection
    {
        $command = "docker ps -a --filter='label=coolify.applicationId={$id}' --format '{{json .}}' ";

        $result = $this->remoteProcessManager->execute($command);

        $formattedResult = self::formatDockerOutputToJson($result->result);

        return $formattedResult;

    }

    public function generateDockerCommand(string $containerId, string $command): string
    {
        return "docker exec {$containerId} bash -c '{$command}'";
    }
}
