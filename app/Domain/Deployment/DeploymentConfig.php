<?php

namespace App\Domain\Deployment;

use App\Models\ApplicationPreview;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\Docker\Output\DockerNetworkContainerInstanceOutput;
use Illuminate\Support\Collection;

class DeploymentConfig
{
    private string $baseDir;

    private SwarmDocker|StandaloneDocker $destination;

    private string $configurationDir;

    private ?ApplicationPreview $preview = null;

    private ?string $customRepository = null;

    private int $customPort = 22;

    private ?string $commit = null;

    private ?Collection $coolifyVariables = null;

    private bool $isThisAdditionalServer;

    private string $containerName;

    private string $workDir;

    private string $envFileName;

    private ?string $addHosts = null;

    private ?string $buildTarget = null;

    public function __construct(private DeploymentContext $deploymentContext)
    {
        $application = $this->deploymentContext->getApplication();

        $pullRequestId = $this->deploymentContext->getApplicationDeploymentQueue()->pull_request_id;
        $this->baseDir = $application
            ->generateBaseDir($this->deploymentContext->getApplicationDeploymentQueue()->deployment_uuid);

        $this->configurationDir = application_configuration_dir().'/'.$application->uuid;
        $this->destination = $this->deploymentContext->getDestination();

        if ($pullRequestId !== 0) {
            $this->preview = $this->deploymentContext->getApplication()->generate_preview_fqdn($this->deploymentContext->getApplicationDeploymentQueue()->pull_request_id);
        }

        ['repository' => $this->customRepository, 'port' => $this->customPort] = $this->deploymentContext->getApplication()->customRepository();

        $this->isThisAdditionalServer = $application
            ->additional_servers()->wherePivot('server_id', $this->deploymentContext->getCurrentServer()->id)->count() > 0;

        $this->containerName = generateApplicationContainerName($application, $this->deploymentContext->getApplicationDeploymentQueue()->pull_request_id);
        $this->workDir = "{$this->baseDir}".rtrim($application->base_directory, '/');

        $pullRequestId = $this->deploymentContext->getApplicationDeploymentQueue()->pull_request_id;
        $this->envFileName = $pullRequestId !== 0 ? '.env.pr-'.$pullRequestId : '.env';

        if ($application->dockerfile_target_build) {
            $this->buildTarget = "--target {$application->dockerfile_target_build} ";
        }

    }

    public function useBuildServer(): bool
    {
        return $this->deploymentContext->getBuildServerSettings()['useBuildServer'];
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function getDestination(): SwarmDocker|StandaloneDocker
    {
        return $this->destination;
    }

    public function getConfigurationDir(): string
    {
        return $this->configurationDir;
    }

    public function getPreview(): ?ApplicationPreview
    {
        return $this->preview;
    }

    public function getCustomPort(): int
    {
        return $this->customPort;
    }

    public function getCustomRepository(): ?string
    {
        return $this->customRepository;
    }

    public function setCommit(string $commit)
    {
        $this->commit = $commit;
    }

    public function getCommit(): ?string
    {
        return $this->commit;
    }

    public function setCoolifyVariables(Collection $variables)
    {
        $this->coolifyVariables = $variables;
    }

    public function getCoolifyVariables(): ?Collection
    {
        return $this->coolifyVariables;
    }

    public function getCoolifyVariablesAsKeyValueString(): string
    {
        if (! $this->coolifyVariables) {
            return '';
        }

        return $this->coolifyVariables->map(function ($value, $key) {
            return "$key=$value";
        })->implode(' ');
    }

    public function isThisAdditionalServer(): bool
    {
        return $this->isThisAdditionalServer;
    }

    public function getContainerName(): string
    {
        return $this->containerName;
    }

    public function getWorkDir(): string
    {
        return $this->workDir;
    }

    public function getEnvFileName(): string
    {
        return $this->envFileName;
    }

    public function isForceRebuild(): bool
    {
        return $this->deploymentContext->getApplicationDeploymentQueue()->force_rebuild === true;
    }

    public function getAddHosts(): string
    {
        if ($this->addHosts !== null) {
            return $this->addHosts;
        }

        $dockerHelper = $this->deploymentContext->getDockerProvider()
            ->forServer($this->deploymentContext->getServerFromDeploymentQueue());

        $destination = $this->getDestination();
        $allContainers = $dockerHelper->getContainersInNetwork($destination->network);
        $filteredContainers = $allContainers->exceptContainers(['coolify-proxy'])
            ->filterNotRegex('/-(\d{12})/');

        $this->addHosts = $filteredContainers->getContainers()->map(function (DockerNetworkContainerInstanceOutput $container) {
            $name = $container->containerName();
            $ip = $container->ipv4WithoutMask();

            return "--add-host $name:$ip";
        })->implode(' ');

        return $this->addHosts;
    }

    public function isRestartOnly(): bool
    {
        // TODO: Set
        return false;
    }

    public function getBuildTarget(): ?string
    {
        return $this->buildTarget;
    }
}
