<?php

namespace App\Services\Docker\Output;

use Illuminate\Support\Collection;

class DockerNetworkContainerOutput
{
    private array $endpoints = [];

    public function addEndpoint(DockerNetworkContainerInstanceOutput $endpoint): void
    {
        $this->endpoints[] = $endpoint;
    }

    public function getEndpoints(): array
    {
        return $this->endpoints;
    }

    /**
     * @return array
     */
    public function exceptContainers(array $except): DockerNetworkContainerOutput
    {
        $newInstance = new self();
        $newInstance->endpoints = array_filter($this->endpoints, function (DockerNetworkContainerInstanceOutput $endpoint) use ($except) {
            return ! in_array($endpoint->containerName(), $except);
        });

        return $newInstance;
    }

    public function filterNotRegex(string $regex): DockerNetworkContainerOutput
    {
        $newInstance = new self();
        $newInstance->endpoints = array_filter($this->endpoints, function (DockerNetworkContainerInstanceOutput $endpoint) use ($regex) {
            return ! preg_match($regex, $endpoint->containerName());
        });

        return $newInstance;
    }

    public function getContainers(): Collection
    {
        return collect($this->endpoints);
    }
}

class DockerNetworkContainerInstanceOutput
{
    public function __construct(private string $containerId, private string $containerName,
        private string $endpointId, private string $macAddress,
        private string $ipv4, private string $ipv6) {}

    public function containerName(): string
    {
        return $this->containerName;
    }

    public function ipv4(): string
    {
        return $this->ipv4;
    }

    public function ipv4WithoutMask(): string
    {
        return explode('/', $this->ipv4)[0];
    }
}
