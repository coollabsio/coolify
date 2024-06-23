<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentBaseAction;
use App\Domain\Deployment\DeploymentOutput;
use JetBrains\PhpStorm\ArrayShape;

class DeploymentActionRestart extends DeploymentBaseAction
{
    public function run(): void
    {
        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: 'Running DeploymentActionRestart::run()'));

        $this->restartContainer($this->deploymentConfig->application->uuid);

    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        return [];
    }

    public function buildImage(): void
    {
        // TODO: Implement buildImage() method.
    }
}
