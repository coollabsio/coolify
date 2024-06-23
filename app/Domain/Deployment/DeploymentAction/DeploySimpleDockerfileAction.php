<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentDockerfileBaseAction;
use JetBrains\PhpStorm\ArrayShape;

class DeploySimpleDockerfileAction extends DeploymentDockerfileBaseAction
{
    public function run(): void
    {
        $application = $this->getApplication();
        $server = $this->getContext()->getCurrentServer();

        $this->addSimpleLog("Starting experimental deployment of {$application->name} to {$server->name}");

        $this->prepareBuilderImage();

        $this->writeSimpleDockerfile();
        $this->generateComposeFile();

        $this->addBuildEnvVariablesToDockerfile();

        $this->buildImage();

        $this->addSimpleLog('Building docker image completed');

        $this->pushToDockerRegistry();
        $this->rollingUpdate();

    }

    private function writeSimpleDockerfile(): void
    {
        $application = $this->getApplication();
        $dockerFileContents = base64_encode($application->dockerfile);

        $this->writeDockerfileBase64($dockerFileContents);

    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        $application = $this->getApplication();
        $dockerRegistryImageName = $application->docker_registry_image_name;

        $buildImageName = $dockerRegistryImageName ?: $application->uuid;

        return [
            'buildImageName' => $buildImageName.':build',
            'productionImageName' => $buildImageName.':latest',
        ];

    }
}
