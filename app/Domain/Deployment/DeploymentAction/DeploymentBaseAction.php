<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentConfig;
use App\Domain\Deployment\DeploymentOutput;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Exceptions\DeploymentCommandFailedException;
use App\Models\ApplicationDeploymentQueue;
use App\Services\Deployment\DeploymentHelper;
use App\Services\Docker\DockerHelper;
use Illuminate\Support\Collection;

abstract class DeploymentBaseAction
{
    private ApplicationDeploymentQueue $applicationDeploymentQueue;

    private DeploymentHelper $deploymentHelper;

    // TODO: DeploymentHelper extract
    private DockerHelper $dockerHelper;

    private DeploymentConfig $deploymentConfig;

    private Collection $savedOutputs;

    public function __construct(ApplicationDeploymentQueue $applicationDeploymentQueue, DeploymentConfig $deploymentConfig, DeploymentHelper $deploymentHelper, DockerHelper $dockerHelper, Collection &$savedOutputs)
    {
        $this->applicationDeploymentQueue = $applicationDeploymentQueue;
        $this->deploymentConfig = $deploymentConfig;
        $this->deploymentHelper = $deploymentHelper;
        $this->dockerHelper = $dockerHelper;
        $this->savedOutputs = $savedOutputs;
    }

    protected function prepareBuilderImage(): void
    {
        $helperImage = config('coolify.helper_image');

        $serverHomeDir = $this->deploymentHelper->executeCommand('echo $HOME');

        $dockerConfigFileExists = $this->deploymentHelper->executeCommand("test -f {$serverHomeDir->result}/.docker/config.json && echo 'OK' || echo 'NOK'");
        if ($this->deploymentConfig->useBuildServer) {
            if ($dockerConfigFileExists->result === 'NOK') {
                throw new DeploymentCommandFailedException('Docker config file not found on build server. Please make sure you have logged in to Docker on the build server.');
            }

            $runHelperImageCommand = "docker run -d --name {$this->applicationDeploymentQueue->deployment_uuid} --rm -v {$serverHomeDir->result}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
        } else {
            if ($dockerConfigFileExists->result === 'OK') {
                $runHelperImageCommand = "docker run -d --network {$this->deploymentConfig->destination->network} --name {$this->applicationDeploymentQueue->deployment_uuid} --rm -v {$serverHomeDir->result}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            } else {
                $runHelperImageCommand = "docker run -d --network {$this->deploymentConfig->destination->network} --name {$this->applicationDeploymentQueue->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            }
        }

        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: "Preparing container with helper image: $helperImage."));

        $this->deploymentHelper->executeAndSave([
            new RemoteCommand("docker rm -f {$this->applicationDeploymentQueue->deployment_uuid}", hidden: true, ignoreErrors: true),
        ], $this->applicationDeploymentQueue, $this->savedOutputs);

        $this->deploymentHelper->executeAndSave([
            new RemoteCommand($runHelperImageCommand, hidden: true),
            new RemoteCommand("docker exec {$this->applicationDeploymentQueue->deployment_uuid} bash -c 'mkdir -p {$config->baseDir}'"),
        ], $this->applicationDeploymentQueue, $this->savedOutputs);

        $this->runPreDeploymentCommand();
    }

    protected function generateImageNames()
    {
        $application = $this->deploymentConfig->application;

        if ($application->dockerfile) {
            if ($application->docker_registry_image_name) {
                $this->deploymentConfig->buildImageName = "{$application->docker_registry_image_name}:build";
                $this->deploymentConfig->productionImageName = "{$application->docker_registry_image_name}:latest";
            } else {
                $this->deploymentConfig->buildImageName = "{$application->uuid}:build";
                $this->deploymentConfig->productionImageName = "{$application->uuid}:latest";
            }

            return;
        }

        if ($application->build_pack === 'dockerimage') {

        }
    }

    protected function checkGitIfBuildNeeded()
    {
        $commands = $this->deploymentConfig->application->generateGitImportCommands(
            deployment_uuid: $this->applicationDeploymentQueue->deployment_uuid,
            pull_request_id: $this->applicationDeploymentQueue->pull_request_id,
            git_type: $this->applicationDeploymentQueue->git_type,
            commit: $this->applicationDeploymentQueue->commit
        );

        $localBranch = $commands['branch'];

        if ($this->applicationDeploymentQueue->pull_request_id !== 0) {
            $localBranch = "pull/{$this->applicationDeploymentQueue->pull_request_id}/head";
        }

        $privateKey = $this->deploymentConfig->application->private_key->private_key;

        if ($privateKey) {
            $privateKeyEncoded = base64_encode($privateKey);

            $this->deploymentHelper->executeAndSave([
                new RemoteCommand($this->dockerHelper->generateDockerCommand($this->applicationDeploymentQueue->deployment_uuid, 'mkdir -p /root/.ssh')),
                new RemoteCommand($this->dockerHelper->generateDockerCommand($this->applicationDeploymentQueue->deployment_uuid, "echo '{$privateKeyEncoded}' | base64 -d | tee /root/.ssh/id_rsa > /dev/null")),
                new RemoteCommand($this->dockerHelper->generateDockerCommand($this->applicationDeploymentQueue->deployment_uuid, 'chmod 600 /root/.ssh/id_rsa')),
                new RemoteCommand($this->dockerHelper->generateDockerCommand($this->applicationDeploymentQueue->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->deploymentConfig->customPort} -o Port={$this->deploymentConfig->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git ls-remote {$commands['fullRepoUrl']} {$localBranch}"), hidden: true, save: 'git_commit_sha'),

            ], $this->applicationDeploymentQueue, $this->savedOutputs);
        } else {
            $this->deploymentHelper->executeAndSave([
                new RemoteCommand($this->dockerHelper->generateDockerCommand($this->applicationDeploymentQueue->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->deploymentConfig->customPort} -o Port={$this->deploymentConfig->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git ls-remote {$commands['fullRepoUrl']} {$localBranch}"), hidden: true, save: 'git_commit_sha'),
            ], $this->applicationDeploymentQueue, $this->savedOutputs);
        }

        if ($this->savedOutputs->has('git_commit_sha') && ! $this->applicationDeploymentQueue->rollback) {
            $this->deploymentConfig->commit = $this->savedOutputs->get('git_commit_sha')->before("\t");
            $this->applicationDeploymentQueue->commit = $this->deploymentConfig->commit;
            $this->applicationDeploymentQueue->save();
        }

        $this->setCoolifyVariables();
    }

    private function setCoolifyVariables(): void
    {
        $variables = collect();
        $variables->put('SOURCE_COMMIT', $this->deploymentConfig->commit);
        if ($this->applicationDeploymentQueue->pull_request_id !== 0) {
            $fqdn = $this->deploymentConfig->application->fqdn;
        } else {
            $fqdn = $this->deploymentConfig->preview->fqdn;
        }

        if ($fqdn) {
            $variables->put('COOLIFY_FQDN', $fqdn);
            $hostname = str($fqdn)->replace('http://', '')
                ->replace('https://', '');

            $variables->put('COOLIFY_URL', $hostname);
        }

        if ($this->deploymentConfig->application->git_branch) {
            $variables->put('COOLIFY_BRANCH', $this->deploymentConfig->application->git_branch);
        }

        $this->deploymentConfig->coolifyVariables = $variables;
    }

    private function runPreDeploymentCommand(): void
    {
        if (empty($this->deploymentConfig->application->pre_deployment_command)) {
            return;
        }

        $containers = $this->getCurrentApplicationContainerStatus($this->deploymentConfig->application->id, $this->applicationDeploymentQueue->pull_request_id);

        if ($containers->count() === 0) {
            return;
        }

        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: 'Executing pre-deployment command (see debug log for output/errors).'));

        foreach ($containers as $container) {
            $containerName = $container->Names;
            // TODO: Implement pre-deployment command
            // @see: run_pre_deployment_command
        }
    }

    abstract public function run(Collection &$savedOutouts): void;

    private function getCurrentApplicationContainerStatus(int $id, ?int $pullRequestId = null, ?bool $includePullrequests = false): Collection
    {
        $containers = collect();

        if ($this->deploymentConfig->server->isSwarm()) {
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
