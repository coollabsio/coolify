<?php

namespace App\Domain\Deployment;

use App\Services\Docker\Output\DockerNetworkContainerInstanceOutput;

/**
 * This class is being provided to DeploymentConfig.
 * This is primarily done so it can be mocked inside the DeploymentConfig during tests.
 */
class DeploymentDockerConfig
{
    private ?string $addHosts;

    private bool $hasRun = false;

    public function __construct(private DeploymentContext $deploymentContext)
    {

    }

    public function generateConfig(): void
    {
        $destination = $this->deploymentContext->getDestination();
        $dockerHelper = $this->deploymentContext->getDockerProvider()
            ->forServer($this->deploymentContext->getServerFromDeploymentQueue());

        $destination = $this->getDestination();
        $allContainers = $dockerHelper->getContainersInNetwork($destination->network);
        $filteredContainers = $allContainers->exceptContainers(['coolify-proxy'])
            ->filterNotRegex('/-(\d{12})/');

        $this->addHosts = $filteredContainers->getContainers()->map(function (DockerNetworkContainerInstanceOutput $container) {
            $name = $container->containerName();
            $ip = $container->ipv4WithoutMask();

            return "--add-host $name:$ip";
        })->implode(' ');
    }

    public function getAddHosts(): ?string
    {

        if (! $this->hasRun) {
            $this->generateConfig();
            $this->hasRun = true;
        }

        return $this->addHosts;
    }
}
