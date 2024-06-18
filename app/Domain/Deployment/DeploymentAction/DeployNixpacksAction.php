<?php

namespace App\Domain\Deployment\DeploymentAction;

use JetBrains\PhpStorm\ArrayShape;

class DeployNixpacksAction extends DeploymentBaseAction
{
    public function run(): void
    {

        $server = $this->context->getCurrentServer();
        $customRepository = $this->context->getCustomRepository();
        $application = $this->context->getApplication();

        $this->addSimpleLog("Starting deployment of {$customRepository['repository']}:{$application->git_branch} to {$server->name}.");
        $this->addSimpleLog('Starting DeployNixpacksAction::run');

        $this->prepareBuilderImage();
        $this->checkGitIfBuildNeeded();

        $deploymentConfig = $this->context->getDeploymentConfig();

        if (! $deploymentConfig->isForceRebuild()) {
            $this->checkImageLocallyOrRemote();
            if ($this->shouldSkipBuild()) {
                return;
            }
        }
    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        $application = $this->getDeploymentConfig()->application;
        $applicationDeploymentQueue = $this->getApplicationDeploymentQueue();

        $commit = $applicationDeploymentQueue->commit;

        if ($application->docker_registry_image_name) {
            return [
                'buildImageName' => "{$application->docker_registry_image_name}:{$commit}-build",
                'productionImageName' => "{$application->docker_registry_image_name}:{$commit}",
            ];
        }

        return [
            'buildImageName' => "{$application->uuid}:{$commit}-build",
            'productionImageName' => "{$application->uuid}:{$commit}",
        ];
    }
}
