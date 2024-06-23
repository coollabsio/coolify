<?php

use App\Domain\Deployment\DeploymentConfig;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\StandaloneDocker;

it('can create an instance of a deployment config', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $context = getContextForApplicationDeployment($deploymentQueue);

    $config = new DeploymentConfig($context);

    expect($config)->toBeInstanceOf(DeploymentConfig::class);
});

it('should not use build server when there is no server available', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $useBuildServer = $config->useBuildServer();

    expect($useBuildServer)
        ->toBeFalse();
});

it('should use build server when there is a server available', function () {
    $application = Application::factory()->create();

    $application->settings->is_build_server_enabled = true;
    $application->settings->save();

    $project = $application->environment->project;

    $buildServer = Server::factory()->create([
        'team_id' => $project->team_id,
    ]);

    $buildServer->settings->is_reachable = true;
    $buildServer->settings->is_build_server = true;
    $buildServer->settings->save();

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
        'application_id' => $application->id,
    ]);

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $useBuildServer = $config->useBuildServer();

    expect($useBuildServer)
        ->toBeTrue();
});

it('is able to fetch the base dir', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $baseDir = $config->getBaseDir();

    expect($baseDir)
        ->toBe("/artifacts/{$deploymentQueue->deployment_uuid}");
});

it('is able to fetch the destination', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $destination = $config->getDestination();

    expect($destination)
        ->toBeInstanceOf(StandaloneDocker::class)
        ->and($destination->server_id)
        ->toBe($deploymentQueue->server_id);
});

it('is able to fetch the configuration dir', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $configurationDir = $config->getConfigurationDir();

    expect($configurationDir)
        ->toBe("/data/coolify/applications/{$deploymentQueue->application->uuid}");
});

it('should return return null on preview when there is no preview available', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $preview = $config->getPreview();

    expect($preview)
        ->toBeNull();
});

it('should return a preview when it is a preview deployment', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
        'pull_request_id' => 1,
    ]);

    $previewEntity = ApplicationPreview::factory()->create([
        'application_id' => $deploymentQueue->application_id,
        'pull_request_id' => 1,
    ]);

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $preview = $config->getPreview();

    expect($preview)
        ->toBeInstanceOf(ApplicationPreview::class)
        ->and($preview->id)
        ->toBe($previewEntity->id);
});

it('should be able to fetch the custom port on a default basis', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $customPort = $config->getCustomPort();

    expect($customPort)
        ->toBe(22);
});

it('should be able to fetch the custom port when there is a custom repository for the application', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $application = $deploymentQueue->application;

    $application->git_repository = 'ssh://git@github.com:2222/coollabsio/coolify.git';
    $application->save();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $customPort = $config->getCustomPort();

    expect($customPort)
        ->toBe(2222);
});

it('should be able to fetch the custom repository on a default basis', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $customRepository = $config->getCustomRepository();

    expect($customRepository)
        ->toBe('coollabsio/coolify');
});

it('should be able to fetch the custom repository when there is a custom repository for the application', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $application = $deploymentQueue->application;

    $application->git_repository = 'git@github.com:2222/some/repo.git';

    $application->save();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $customRepository = $config->getCustomRepository();

    expect($customRepository)
        ->toBe('git@github.com:some/repo.git');

});

it('should be able to get the git commit when it is null', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    expect($config->getCommit())
        ->toBeNull();
});

it('should be able to get the git commit when it is set', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);
    $config->setCommit('81024772fb19308dd49c21ac7968cc340b1a0784');

    expect($config->getCommit())
        ->toBe('81024772fb19308dd49c21ac7968cc340b1a0784');
});

it('should be able to get the coolify variables when they are not set', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $coolifyVariables = $config->getCoolifyVariables();

    expect($coolifyVariables)
        ->toBeNull();
});

it('should be able to get the coolify variables when they are set', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $coolifyVariables = collect([
        'COOLIFY_VAR' => 'coolify',
    ]);

    $config->setCoolifyVariables($coolifyVariables);

    expect($config->getCoolifyVariables())
        ->toBe($coolifyVariables);
});

it('should be able to fetch the coolify variables as string when it is not set', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    expect($config->getCoolifyVariablesAsKeyValueString())
        ->toBe('');
});

it('shou;d be able to fetch the coolify variables as string when they are set', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $coolifyVariables = collect([
        'COOLIFY_VAR' => 'coolify',
    ]);

    $config->setCoolifyVariables($coolifyVariables);

    expect($config->getCoolifyVariablesAsKeyValueString())
        ->toBe('COOLIFY_VAR=coolify');
});

it('should not be an additional server by default', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    expect($config->isThisAdditionalServer())
        ->toBeFalse();
});

it('should be an additional server when it is set', function () {
    expect(false)
        ->toBeTrue();
})->skip('Test to be implemented.');

it('should be able to fetch the container name', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    // There is some unique now() being provided, therefore it should not be equal to the deployment uuid only.
    expect($config->getContainerName())
        ->toStartWith($deploymentQueue->application->uuid)
        ->and($config->getContainerName())
        ->not->toEndWith($deploymentQueue->deployment_uuid);
});

it('should be able to fetch the work dir', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $workDir = $config->getWorkDir();

    expect($workDir)
        ->toBe("/artifacts/{$deploymentQueue->deployment_uuid}");

});

it('should be able to fetch the env file name', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $envFileName = $config->getEnvFileName();

    expect($envFileName)
        ->toBe('.env');

});

it('should be able to fetch the env file name for a pull request', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
        'pull_request_id' => 22,
    ]);

    $previewEntity = ApplicationPreview::factory()->create([
        'application_id' => $deploymentQueue->application_id,
        'pull_request_id' => 22,
    ]);

    $config = createConfigForDeploymentQueue($deploymentQueue);

    $envFileName = $config->getEnvFileName();

    expect($envFileName)
        ->toBe('.env.pr-22');

});

it('should be able to check if its a forced rebuild for default values', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    expect($config->isForceRebuild())
        ->toBeFalse();

});

it('should be able to check if it is a forced rebuild when set', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
        'force_rebuild' => true,
    ]);

    $config = createConfigForDeploymentQueue($deploymentQueue);

    expect($config->isForceRebuild())
        ->toBeTrue();
});

it('should be able to fetch the addHosts string', function () {

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    expect($config->getAddHosts())
        ->toContain('--add-host coolify-redis:')
        ->toContain('--add-host coolify-db:')
        ->toContain('--add-host coolify:');
});

it('is able to fetch the target build when it is not set', function () {
    $deploymentQueue = ApplicationDeploymentQueue::factory()->create();

    $config = createConfigForDeploymentQueue($deploymentQueue);

    expect($config->getBuildTarget())
        ->toBeNull();
});

it('is able to fetch the target build when it is set', function () {
    $application = Application::factory()->create([
        'dockerfile_target_build' => 'production',
    ]);

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
        'application_id' => $application->id,
    ]);

    $config = createConfigForDeploymentQueue($deploymentQueue);

    expect($config->getBuildTarget())
        ->toBe('--target production ');
});

function createConfigForDeploymentQueue(ApplicationDeploymentQueue $applicationDeploymentQueue): DeploymentConfig
{
    $context = getContextForApplicationDeployment($applicationDeploymentQueue);

    return new DeploymentConfig($context);
}
