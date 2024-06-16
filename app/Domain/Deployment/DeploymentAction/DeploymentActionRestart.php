<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentConfig;
use App\Domain\Deployment\DeploymentOutput;
use App\Models\ApplicationDeploymentQueue;
use App\Services\Deployment\DeploymentHelper;
use App\Services\Docker\DockerHelper;
use Illuminate\Support\Collection;

class DeploymentActionRestart extends DeploymentBaseAction
{
    private ApplicationDeploymentQueue $applicationDeploymentQueue;

    private DeploymentHelper $deploymentHelper;

    private DockerHelper $dockerHelper;

    public function __construct(ApplicationDeploymentQueue $applicationDeploymentQueue, DeploymentConfig $deploymentConfig, DeploymentHelper $deploymentHelper, DockerHelper $dockerHelper)
    {
        parent::__construct($applicationDeploymentQueue, $deploymentConfig, $deploymentHelper, $dockerHelper);
        $this->applicationDeploymentQueue = $applicationDeploymentQueue;
        $this->deploymentHelper = $deploymentHelper;
        $this->dockerHelper = $dockerHelper;
    }

    public function run(Collection &$savedOutputs): void
    {
        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: 'Running DeploymentActionRestart::run()'));

        $this->restartContainer($this->deploymentConfig->application->uuid);

    }
}
