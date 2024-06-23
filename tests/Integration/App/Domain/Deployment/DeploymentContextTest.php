<?php

use App\Domain\Deployment\DeploymentConfig;
use App\Domain\Deployment\DeploymentContext;
use App\Domain\Deployment\DeploymentResult;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\Deployment\DeploymentHelper;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerHelper;
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

it('should be able to fetch the current server', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $currentServer = $context->getCurrentServer();

    expect($currentServer)->toBeInstanceOf(Server::class)
        ->and($currentServer->id)->toBe($deploymentQueue->server_id);

});

it('should be able to use build server when the proper config is in place', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    generateBuildServer($deploymentQueue);

    $context = getContextForApplicationDeployment($deploymentQueue);

    $useBuildServer = $context->getDeploymentConfig()->useBuildServer();

    expect($useBuildServer)
        ->toBeTrue();
});

it('should be able to switch the current server to the build server', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $buildServer = generateBuildServer($deploymentQueue);

    $context = getContextForApplicationDeployment($deploymentQueue);

    $context->switchToBuildServer();

    $currentServer = $context->getCurrentServer();

    expect($currentServer->id)->toBe($buildServer->id);
});

it('should be able to switch back to the original server', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $buildServer = generateBuildServer($deploymentQueue);

    $context = getContextForApplicationDeployment($deploymentQueue);

    $context->switchToBuildServer();

    $currentServer = $context->getCurrentServer();

    expect($currentServer->id)->toBe($buildServer->id);

    $context->switchToOriginalServer();

    $currentServer = $context->getCurrentServer();

    expect($currentServer->id)->toBe($deploymentQueue->server_id);
});

it('is able to switch to the build server although there is no build server configured', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $context->switchToBuildServer();

    $currentServer = $context->getCurrentServer();

    expect($currentServer->id)->toBe($deploymentQueue->server_id);

    $context->switchToOriginalServer();

    $currentServer = $context->getCurrentServer();

    expect($currentServer->id)->toBe($deploymentQueue->server_id);
});

it('should be able to fetch the deployment result', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $deploymentResult = $context->getDeploymentResult();

    expect($deploymentResult)->toBeInstanceOf(DeploymentResult::class);
});

it('should be able to fetch the deployment config', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $deploymentConfig = $context->getDeploymentConfig();

    expect($deploymentConfig)->toBeInstanceOf(DeploymentConfig::class);
});

it('should be able to fetch the deployment helper', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $deploymentHelper = $context->getDeploymentHelper();

    expect($deploymentHelper)->toBeInstanceOf(DeploymentHelper::class);
});

it('should be able to fetch the docker helper', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $dockerHelper = $context->getDockerHelper();

    expect($dockerHelper)->toBeInstanceOf(DockerHelper::class);
});

it('should be able to fetch the server from the deployment queue', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $server = $context->getServerFromDeploymentQueue();

    expect($server)->toBeInstanceOf(Server::class)
        ->and($server->id)->toBe($deploymentQueue->server_id);
});

it('should be able to add simple log to the deployment', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $context->addSimpleLog('This is a simple log');

    $deploymentQueue = $deploymentQueue->refresh();

    $logs = $deploymentQueue->logs;

    $logsAsJson = json_decode($logs, true);

    expect($logsAsJson)->toBeArray()
        ->and($logsAsJson[0]['output'])->toBe('This is a simple log')
        ->and($logsAsJson[0]['command'])->toBe('')
        ->and($logsAsJson[0]['type'])->toBe('stdout')
        ->and($logsAsJson[0]['hidden'])->toBeFalse()
        ->and($logsAsJson[0]['timestamp'])->toBeString()
        ->and($logsAsJson[0]['batch'])->toBeNumeric()
        ->and($logsAsJson[0]['order'])->toBe(1);
});

it('should be able to fetch the destination', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $destination = $context->getDestination();

    expect($destination)->toBeInstanceOf(StandaloneDocker::class);
});

it('should be able to fetch the custom repository', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $customRepository = $context->getCustomRepository();

    expect($customRepository)->toBeArray()
        ->and($customRepository['repository'])->toBe('coollabsio/coolify')
        ->and($customRepository['port'])->toBe(22);
});

it('is able to get the docker provider', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $dockerProvider = $context->getDockerProvider();

    expect($dockerProvider)->toBeInstanceOf(DockerProvider::class);
});

it('is able to get the deployment provider', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $deploymentProvider = $context->getDeploymentProvider();

    expect($deploymentProvider)->toBeInstanceOf(DeploymentProvider::class);
});

it('is able to get the application deployment queue', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $applicationDeploymentQueue = $context->getApplicationDeploymentQueue();

    expect($applicationDeploymentQueue)->toBeInstanceOf(ApplicationDeploymentQueue::class)
        ->and($applicationDeploymentQueue->id)->toBe($deploymentQueue->id);
});

function generateBuildServer(ApplicationDeploymentQueue $deploymentQueue): Server
{
    $deploymentQueue->application->settings->is_build_server_enabled = true;
    $deploymentQueue->application->settings->save();

    $teamId = $deploymentQueue->application->environment->project->team_id;

    $buildServer = Server::factory()->create([
        'team_id' => $teamId,
    ]);

    $buildServer->settings->is_reachable = true;
    $buildServer->settings->is_build_server = true;
    $buildServer->settings->save();

    return $buildServer;
}
