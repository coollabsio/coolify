<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentDockerfileBaseAction;
use JetBrains\PhpStorm\ArrayShape;

class DeployDockerfileAction extends DeploymentDockerfileBaseAction
{
    public function run(): void
    {
        $application = $this->getApplication();

        if ($application->dockerfile_location) {
            $this->context->getDeploymentResult()->setDockerFileLocation($application->dockerfile_location);
        }

        $this->prepareBuilderImage();
        $this->checkGitIfBuildNeeded();
        $this->cloneRepository();

        if (! $this->context->getDeploymentConfig()->isForceRebuild()) {
            $this->checkImageLocallyOrRemote();

            if ($this->shouldSkipBuild()) {
                return;
            }
        }

        $this->cleanupGit();
        $this->generateComposeFile();
        $this->addBuildEnvVariablesToDockerfile();
        $this->buildImage();
        $this->pushToDockerRegistry();
        $this->rollingUpdate();
    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        $application = $this->getApplication();
        $deployment = $this->getContext()->getApplicationDeploymentQueue();

        $isPullRequest = $deployment->pull_request_id !== 0;

        if ($isPullRequest) {

            $baseName = $application->docker_registry_image_name
                ? $application->docker_registry_image_name.':pr-'.$deployment->pull_request_id
                : $application->uuid.':pr-'.$deployment->pull_request_id;
        } else {
            $dockerImageTag = str($this->getContext()->getDeploymentConfig()->getCommit())->substr(0, 128);
            $baseName = $application->docker_registry_image_name
                ? $application->docker_registry_image_name.':'.$dockerImageTag
                : $application->uuid.':'.$dockerImageTag;
        }

        return [
            'buildImageName' => $baseName.'-build',
            'productionImageName' => $baseName,
        ];
    }
}
