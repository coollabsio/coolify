<?php

namespace App\Domain\Deployment;

use App\Models\ApplicationPreview;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;

class DeploymentConfig
{
    private string $baseDir;

    private SwarmDocker|StandaloneDocker $destination;

    private string $configurationDir;

    private ?ApplicationPreview $preview = null;

    private ?string $customRepository = null;

    private int $customPort = 22;

    private string $commit;

    private Collection $coolifyVariables;

    private bool $isThisAdditionalServer;

    private string $containerName;

    private string $workDir;

    private string $envFileName;

    public function __construct(private DeploymentContext $deploymentContext)
    {
        $pullRequestId = $this->deploymentContext->getApplicationDeploymentQueue()->pull_request_id;
        $this->baseDir = $this->deploymentContext->getApplication()
            ->generateBaseDir($this->deploymentContext->getApplicationDeploymentQueue()->deployment_uuid);

        $this->configurationDir = application_configuration_dir().'/'.$this->deploymentContext->getApplication()->id;
        $this->destination = $this->deploymentContext->getDestination();

        if ($pullRequestId !== 0) {
            $this->preview = $this->deploymentContext->getApplication()->generate_preview_fqdn($this->deploymentContext->getApplicationDeploymentQueue()->pull_request_id);
        }

        ['repository' => $this->customRepository, 'port' => $this->customPort] = $this->deploymentContext->getApplication()->customRepository();

        $this->isThisAdditionalServer = $this->deploymentContext->getApplication()
            ->additional_servers()->wherePivot('server_id', $this->deploymentContext->getCurrentServer()->id)->count() > 0;

        $this->containerName = generateApplicationContainerName($this->deploymentContext->getApplication(), $this->deploymentContext->getApplicationDeploymentQueue()->pull_request_id);
        $this->workDir = "{$this->baseDir}".rtrim($this->deploymentContext->getApplication()->base_directory, '/');

        $pullRequestId = $this->deploymentContext->getApplicationDeploymentQueue()->pull_request_id;
        $this->envFileName = $pullRequestId !== 0 ? '.env.pr-'.$pullRequestId : '.env';
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

    public function getCommit(): string
    {
        return $this->commit;
    }

    public function setCoolifyVariables(Collection $variables)
    {
        $this->coolifyVariables = $variables;
    }

    public function getCoolifyVariables(): Collection
    {
        return $this->coolifyVariables;
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
        return $this->deploymentContext->getApplicationDeploymentQueue()->force_rebuild;
    }
}
