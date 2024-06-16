<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Services\Deployment\DeploymentHelper;
use App\Services\Docker\DockerHelper;

class DeploymentActionRestart extends DeploymentBaseAction
{
    public function __construct(ApplicationDeploymentQueue $applicationDeploymentQueue, Server $server, Application $application, DeploymentHelper $deploymentHelper, DockerHelper $dockerHelper)
    {
        parent::__construct($applicationDeploymentQueue, $server, $application, $deploymentHelper, $dockerHelper);
    }
}
