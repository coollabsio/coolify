<?php

namespace App\Domain\Deployment;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\Deployment\DeploymentHelper;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerHelper;
use App\Services\Docker\DockerProvider;
use JetBrains\PhpStorm\ArrayShape;

class DeploymentContext
{
    private ?array $buildServerConfig = null;

    private DeploymentResult $deploymentResult;

    private DeploymentConfig $deploymentConfig;

    private Server $currentServer;

    public function __construct(private ApplicationDeploymentQueue $applicationDeploymentQueue,
        private DockerProvider $dockerProvider, private DeploymentProvider $deploymentProvider)
    {
        $this->currentServer = Server::find($this->applicationDeploymentQueue->server_id);

        $this->deploymentResult = new DeploymentResult();
        $this->deploymentConfig = new DeploymentConfig($this);

    }

    public function getApplication(): Application
    {
        return $this->applicationDeploymentQueue->application;
    }

    public function switchToBuildServer(): void
    {
        $buildServerConfig = $this->getBuildServerSettings();
        if ($buildServerConfig['useBuildServer']) {
            $this->currentServer = $buildServerConfig['buildServer'];
        } else {
            $this->currentServer = $buildServerConfig['originalServer'];
        }
    }

    public function getCurrentServer(): Server
    {
        return $this->currentServer;
    }

    public function switchToOriginalServer(): void
    {
        $this->currentServer = $this->getServerFromDeploymentQueue();
    }

    public function getDeploymentResult(): DeploymentResult
    {
        return $this->deploymentResult;
    }

    public function getDeploymentConfig(): DeploymentConfig
    {
        return $this->deploymentConfig;
    }

    #[ArrayShape(['commands' => 'string', 'branch' => 'string', 'fullRepoUrl' => 'string'])]
    public function generateGitImportCommands(): array
    {
        $applicationDeploymentQueue = $this->getApplicationDeploymentQueue();

        $commands = $this->getApplication()->generateGitImportCommands(
            deployment_uuid: $applicationDeploymentQueue->deployment_uuid,
            pull_request_id: $applicationDeploymentQueue->pull_request_id,
            git_type: $applicationDeploymentQueue->git_type,
            commit: $applicationDeploymentQueue->commit
        );

        return $commands;
    }

    #[ArrayShape(['useBuildServer' => 'bool', 'buildServer' => Server::class, 'originalServer' => Server::class])]
    public function getBuildServerSettings(): array
    {
        if ($this->buildServerConfig) {
            return $this->buildServerConfig;
        }
        $application = $this->getApplication();

        $originalServer = $this->getServerFromDeploymentQueue();
        $buildServerArray = [
            'useBuildServer' => false,
            'buildServer' => $originalServer,
            'originalServer' => $originalServer,
        ];

        $this->buildServerConfig = $buildServerArray;

        if (! $application->settings->is_build_server_enabled) {
            return $this->buildServerConfig;
        }

        $teamId = $application->environment->project->team_id;

        $buildServers = $this->getBuildServersForTeamId($teamId);

        if ($buildServers->isEmpty()) {
            $this->addSimpleLog('No suitable build server found. Using the deployment server.');

            return $buildServerArray;
        }

        $randomBuildServer = $buildServers->random();
        $this->addSimpleLog("Found a suitable build server: {$randomBuildServer->name}");

        $buildServerArray['buildServer'] = $randomBuildServer;
        $buildServerArray['useBuildServer'] = true;

        $this->buildServerConfig = $buildServerArray;

        return $this->buildServerConfig;
    }

    public function getDeploymentHelper(): DeploymentHelper
    {
        return $this->deploymentProvider->forServer($this->currentServer);
    }

    public function getDockerHelper(): DockerHelper
    {
        return $this->dockerProvider->forServer($this->currentServer);
    }

    public function getServerFromDeploymentQueue(): Server
    {
        return Server::find($this->applicationDeploymentQueue->server_id);
    }

    private function getBuildServersForTeamId(int $teamId)
    {
        return Server::buildServers($teamId)->get();
    }

    public function addSimpleLog(string $log): void
    {
        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: $log));
    }

    public function getDestination(): StandaloneDocker|SwarmDocker
    {
        return $this->getServerFromDeploymentQueue()->destinations()->where('id', $this->applicationDeploymentQueue->destination_id)->first();
    }

    #[ArrayShape(['repository' => 'string', 'port' => 'string'])]
    public function getCustomRepository(): array
    {
        $application = $this->getApplication();

        $customRepository = $application->customRepository();

        return $customRepository;
    }

    public function getDockerProvider(): DockerProvider
    {
        return $this->dockerProvider;
    }

    public function getDeploymentProvider(): DeploymentProvider
    {
        return $this->deploymentProvider;
    }

    public function getApplicationDeploymentQueue(): ApplicationDeploymentQueue
    {
        return $this->applicationDeploymentQueue;
    }
}
