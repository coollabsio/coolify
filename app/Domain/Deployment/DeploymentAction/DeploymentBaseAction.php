<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentConfig;
use App\Domain\Deployment\DeploymentOutput;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Exceptions\DeploymentCommandFailedException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\Deployment\DeploymentHelper;
use App\Services\Docker\DockerHelper;
use Illuminate\Support\Collection;

abstract class DeploymentBaseAction
{
    private ApplicationDeploymentQueue $applicationDeploymentQueue;
    private Server $server;
    private Application $application;
    private DeploymentHelper $deploymentHelper;

    // TODO: DeploymentHelper extract
    private DockerHelper $dockerHelper;

    public function __construct(ApplicationDeploymentQueue $applicationDeploymentQueue, Server $server, Application $application, DeploymentHelper $deploymentHelper, DockerHelper $dockerHelper)
    {
        $this->applicationDeploymentQueue = $applicationDeploymentQueue;
        $this->server = $server;
        $this->application = $application;
        $this->deploymentHelper = $deploymentHelper;
        $this->dockerHelper = $dockerHelper;
    }

    protected function prepareBuilderImage(DeploymentConfig $config, StandaloneDocker|SwarmDocker $destination, Collection &$savedOutputs): void
    {
        $helperImage = config('coolify.helper_image');

        $serverHomeDir = $this->deploymentHelper->executeCommand('echo $HOME');

        $dockerConfigFileExists = $this->deploymentHelper->executeCommand("test -f {$serverHomeDir->result}/.docker/config.json && echo 'OK' || echo 'NOK'");
        if ($config->useBuildServer) {
            if ($dockerConfigFileExists->result === 'NOK') {
                throw new DeploymentCommandFailedException("Docker config file not found on build server. Please make sure you have logged in to Docker on the build server.");
            }

            $runHelperImageCommand = "docker run -d --name {$this->applicationDeploymentQueue->deployment_uuid} --rm -v {$serverHomeDir->result}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
        } else {
            if ($dockerConfigFileExists->result === 'OK') {
                $runHelperImageCommand = "docker run -d --network {$destination->network} --name {$this->applicationDeploymentQueue->deployment_uuid} --rm -v {$serverHomeDir->result}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            } else {
                $runHelperImageCommand = "docker run -d --network {$destination->network} --name {$this->applicationDeploymentQueue->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            }
        }

        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: "Preparing container with helper image: $helperImage."));


        $this->deploymentHelper->executeAndSave([
            new RemoteCommand("docker rm -f {$this->applicationDeploymentQueue->deployment_uuid}", ignoreErrors: true, hidden: true)
        ], $this->applicationDeploymentQueue, $savedOutputs);

        $this->deploymentHelper->executeAndSave([
            new RemoteCommand($runHelperImageCommand, hidden: true),
            new RemoteCommand("docker exec {$this->applicationDeploymentQueue->deployment_uuid} bash -c 'mkdir -p {$config->baseDir}'"),
        ], $this->applicationDeploymentQueue, $savedOutputs);

        $this->runPreDeploymentCommand();
    }

    private function runPreDeploymentCommand(): void
    {
        if (empty($this->application->pre_deployment_command)) {
            return;
        }


        $containers = $this->getCurrentApplicationContainerStatus($this->application->id, $this->applicationDeploymentQueue->pull_request_id);

        if ($containers->count() === 0) {
            return;
        }

        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: 'Executing pre-deployment command (see debug log for output/errors).'));

        foreach ($containers as $container) {
            $containerName = $container->Names;
        }
    }

    private function getCurrentApplicationContainerStatus(int $id, ?int $pullRequestId = null, ?bool $includePullrequests = false): Collection
    {
        $containers = collect();

        if ($this->server->isSwarm()) {
            return $containers;
        }

        $containers = $this->dockerHelper->getContainersForCoolifyLabelId($id);

        $containers = $containers->map(function ($container) use ($pullRequestId, $includePullrequests) {
            $labels = data_get($container, 'Labels');
            if (! str($labels)->contains('coolify.pullRequestId=')) {
                data_set($container, 'Labels', $labels.",coolify.pullRequestId={$pullRequestId}");

                return $container;
            }
            if ($includePullrequests) {
                return $container;
            }
            if (str($labels)->contains("coolify.pullRequestId=$pullRequestId")) {
                return $container;
            }

            return null;
        });

        return $containers;
    }


}
