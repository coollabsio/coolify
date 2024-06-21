<?php

use App\Domain\Deployment\DeploymentContext;
use App\Models\ApplicationDeploymentQueue;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;

it('can create an instance of a deploymentcontext', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    expect($context)->toBeInstanceOf(DeploymentContext::class);
});

function getContextForApplicationDeployment(ApplicationDeploymentQueue $applicationDeploymentQueue): DeploymentContext
{
    // This could be improved, but for now it's fine
    $dockerProvider = app(DockerProvider::class);
    $deploymentProvider = app(DeploymentProvider::class);

    $mockedDockerConfig = deploymentDockerConfigMock('--add-host coolify-proxy:127.0.0.1');

    return new DeploymentContext($applicationDeploymentQueue, $mockedDockerConfig, $dockerProvider, $deploymentProvider);
}
