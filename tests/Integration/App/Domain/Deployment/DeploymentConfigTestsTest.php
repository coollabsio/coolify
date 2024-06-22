<?php

use App\Domain\Deployment\DeploymentContext;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;

it('can create an instance of a deploymentcontext', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    expect($context)->toBeInstanceOf(DeploymentContext::class);
});

it('can fetch the application instance from a deployment context', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    expect($context->getApplication())->toBeInstanceOf(Application::class)
        ->and($context->getApplication()->id)->toBe($deploymentQueue->application_id);

});

it('can fetch the generate git import commands', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $commands = $context->generateGitImportCommands();

    $id = $deploymentQueue->deployment_uuid;
    expect($commands['fullRepoUrl'])
        ->toBe('coollabsio/coolify')
        ->and($commands['branch'])
        ->toBe('master');

    // TODO: Check $commands['commands'] for the correct git commands

});

function getContextForApplicationDeployment(ApplicationDeploymentQueue $applicationDeploymentQueue): DeploymentContext
{
    // This could be improved, but for now it's fine
    $dockerProvider = app(DockerProvider::class);
    $deploymentProvider = app(DeploymentProvider::class);

    $mockedDockerConfig = deploymentDockerConfigMock('--add-host coolify-proxy:127.0.0.1');

    return new DeploymentContext($applicationDeploymentQueue, $mockedDockerConfig, $dockerProvider, $deploymentProvider);
}
