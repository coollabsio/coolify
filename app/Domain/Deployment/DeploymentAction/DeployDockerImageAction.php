<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentDockerfileBaseAction;
use JetBrains\PhpStorm\ArrayShape;

class DeployDockerImageAction extends DeploymentDockerfileBaseAction
{
    public function run(): void
    {
        $application = $this->context->getApplication();

        $dockerImage = $application->docker_registry_image_name;
        $dockerTag = str($application->docker_registry_image_tag)->isEmpty() ? 'latest' : $application->docker_registry_image_tag;

        $server = $this->getContext()->getCurrentServer();

        $this->addSimpleLog("Starting deployment of {$dockerImage}:{$dockerTag} to {$server->name}.");

        $this->prepareBuilderImage();
        $this->generateComposeFile();
        $this->rollingUpdate();
    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        $application = $this->context->getApplication();

        $dockerImage = $application->docker_registry_image_name;
        $dockerTag = str($application->docker_registry_image_tag)->isEmpty() ? 'latest' : $application->docker_registry_image_tag;

        return [
            'buildImageName' => "{$dockerImage}:{$dockerTag}",
            'productionImageName' => "{$dockerImage}:{$dockerTag}",
        ];
    }
}
