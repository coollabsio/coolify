<?php

namespace Tests\Unit\App\Services\Docker\Output;

use App\Services\Docker\Output\DockerNetworkContainerInstanceOutput;
use App\Services\Docker\Output\DockerNetworkContainerOutput;

beforeEach(function () {
    $this->dockerNetworkContainerOutput = new DockerNetworkContainerOutput();
});

it('should be able to add an endpoint', function () {
    $this->dockerNetworkContainerOutput->addEndpoint(generateDockerNetworkContainerInstanceOutput('name'));
    expect($this->dockerNetworkContainerOutput->getEndpoints())->toHaveCount(1);
});

it('should be able to filter out containers', function () {
    $this->dockerNetworkContainerOutput->addEndpoint(generateDockerNetworkContainerInstanceOutput('name'));
    $this->dockerNetworkContainerOutput->addEndpoint(generateDockerNetworkContainerInstanceOutput('name2'));
    $this->dockerNetworkContainerOutput->addEndpoint(generateDockerNetworkContainerInstanceOutput('name3'));

    $filtered = $this->dockerNetworkContainerOutput->exceptContainers(['name2']);
    expect($filtered->getEndpoints())->toHaveCount(2);
});

it('should be able to filter for regex', function () {
    $this->dockerNetworkContainerOutput->addEndpoint(generateDockerNetworkContainerInstanceOutput('name'));
    $this->dockerNetworkContainerOutput->addEndpoint(generateDockerNetworkContainerInstanceOutput('name2'));
    $this->dockerNetworkContainerOutput->addEndpoint(generateDockerNetworkContainerInstanceOutput('name3'));

    $filtered = $this->dockerNetworkContainerOutput->filterNotRegex('/name2/');
    expect($filtered->getEndpoints())->toHaveCount(2);
});

it('should return the proper properties from the instance output', function () {
    $container = generateDockerNetworkContainerInstanceOutput('name');
    expect($container->containerName())->toBe('name')
        ->and($container->ipv4WithoutMask())->toBe('127.0.0.1')
        ->and($container->ipv4())->toBe('127.0.0.1/24');

});

function generateDockerNetworkContainerInstanceOutput(string $name): DockerNetworkContainerInstanceOutput
{
    return new DockerNetworkContainerInstanceOutput('containerId', $name, 'endpointId', 'macAddress', '127.0.0.1/24', 'ipv6');
}
