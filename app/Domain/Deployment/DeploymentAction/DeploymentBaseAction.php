<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentContext;
use App\Domain\Deployment\DeploymentOutput;
use App\Domain\Deployment\Generators\DockerComposeGenerator;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Exceptions\DeploymentCommandFailedException;
use App\Models\Application;
use Illuminate\Support\Collection;
use JetBrains\PhpStorm\ArrayShape;

abstract class DeploymentBaseAction
{
    private const LOCAL_IMAGE_FOUND = 'local_image_found';

    private const GIT_COMMIT_SHA = 'git_commit_sha';

    protected DeploymentContext $context;

    public function __construct(DeploymentContext $deploymentContext)
    {
        $this->context = $deploymentContext;
    }

    public function getContext(): DeploymentContext
    {
        return $this->context;
    }

    abstract public function run(): void;

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    abstract public function generateDockerImageNames(): array;

    protected function prepareBuilderImage(): void
    {
        $helperImage = config('coolify.helper_image');

        $serverHomeDir = $this->context->getDeploymentHelper()->executeCommand('echo $HOME');
        $dockerConfigFileExists = $this->context->getDeploymentHelper()->executeCommand("test -f {$serverHomeDir->result}/.docker/config.json && echo 'OK' || echo 'NOK'");

        $applicationDeploymentQueue = $this->context->getApplicationDeploymentQueue();

        if ($this->context->getDeploymentConfig()->useBuildServer()) {
            if ($dockerConfigFileExists->result === 'NOK') {
                throw new DeploymentCommandFailedException('Docker config file not found on build server. Please make sure you have logged in to Docker on the build server.');
            }

            $runHelperImageCommand = "docker run -d --name {$this->context->getApplicationDeploymentQueue()->deployment_uuid} --rm -v {$serverHomeDir->result}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
        } else {
            if ($dockerConfigFileExists->result === 'OK') {
                $runHelperImageCommand = "docker run -d --network {$this->context->getDeploymentConfig()->getDestination()->network} --name {$this->context->getApplicationDeploymentQueue()->deployment_uuid} --rm -v {$serverHomeDir->result}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            } else {
                $runHelperImageCommand = "docker run -d --network {$this->context->getDeploymentConfig()->getDestination()->network} --name {$this->context->getApplicationDeploymentQueue()->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            }
        }

        $this->context->getApplicationDeploymentQueue()->addDeploymentLog(new DeploymentOutput(output: "Preparing container with helper image: $helperImage."));

        $this->context->getDeploymentHelper()->executeAndSave([
            new RemoteCommand("docker rm -f {$applicationDeploymentQueue->deployment_uuid}", hidden: true, ignoreErrors: true),
        ], $applicationDeploymentQueue, $this->context->getDeploymentResult()->savedLogs);

        $this->context->getDeploymentHelper()->executeAndSave([
            new RemoteCommand($runHelperImageCommand, hidden: true),
            new RemoteCommand("docker exec {$applicationDeploymentQueue->deployment_uuid} bash -c 'mkdir -p {$this->context->getDeploymentConfig()->getBaseDir()}'"),
        ], $applicationDeploymentQueue, $this->context->getDeploymentResult()->savedLogs);

        $this->runPreDeploymentCommand();
    }

    private function deployDockerImageBuildPack(): string
    {
        // @see deploy_dockerimage_buildpack
        return 'image:latest';
    }

    protected function checkGitIfBuildNeeded()
    {
        $application = $this->context->getApplication();
        $applicationDeploymentQueue = $this->context->getApplicationDeploymentQueue();
        $deploymentConfig = $this->context->getDeploymentConfig();
        $commands = $application->generateGitImportCommands(
            deployment_uuid: $applicationDeploymentQueue->deployment_uuid,
            pull_request_id: $applicationDeploymentQueue->pull_request_id,
            git_type: $applicationDeploymentQueue->git_type,
            commit: $applicationDeploymentQueue->commit
        );

        $localBranch = $commands['branch'];

        if ($$applicationDeploymentQueue->pull_request_id !== 0) {
            $localBranch = "pull/{$applicationDeploymentQueue->pull_request_id}/head";
        }

        $privateKey = $application->private_key->private_key;

        $dockerHelper = $this->context->getDockerHelper();
        if ($privateKey) {
            $privateKeyEncoded = base64_encode($privateKey);

            $this->context->getDeploymentHelper()->executeAndSave([
                new RemoteCommand($dockerHelper->generateDockerCommand($applicationDeploymentQueue->deployment_uuid, 'mkdir -p /root/.ssh')),
                new RemoteCommand($dockerHelper->generateDockerCommand($applicationDeploymentQueue->deployment_uuid, "echo '{$privateKeyEncoded}' | base64 -d | tee /root/.ssh/id_rsa > /dev/null")),
                new RemoteCommand($dockerHelper->generateDockerCommand($applicationDeploymentQueue->deployment_uuid, 'chmod 600 /root/.ssh/id_rsa')),
                new RemoteCommand($dockerHelper->generateDockerCommand($applicationDeploymentQueue->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$deploymentConfig->getCustomPort()} -o Port={$deploymentConfig->getCustomPort()} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git ls-remote {$commands['fullRepoUrl']} {$localBranch}"), hidden: true, save: self::GIT_COMMIT_SHA),

            ], $applicationDeploymentQueue, $this->context->getDeploymentResult()->savedLogs);
        } else {
            $this->context->getDeploymentHelper()->executeAndSave([
                new RemoteCommand($dockerHelper->generateDockerCommand($applicationDeploymentQueue->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$deploymentConfig->getCustomPort()} -o Port={$deploymentConfig->getCustomPort()} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git ls-remote {$commands['fullRepoUrl']} {$localBranch}"), hidden: true, save: self::GIT_COMMIT_SHA),
            ], $applicationDeploymentQueue, $this->context->getDeploymentResult()->savedLogs);
        }

        if ($this->context->getDeploymentResult()->savedLogs->has(self::GIT_COMMIT_SHA) && ! $applicationDeploymentQueue->rollback) {
            $this->context->getDeploymentConfig()->setCommit($this->context->getDeploymentResult()->savedLogs->get('git_commit_sha')->before("\t"));
            $applicationDeploymentQueue->commit = $this->context->getDeploymentConfig()->getCommit();
            $applicationDeploymentQueue->save();
        }

        $this->setCoolifyVariables();
    }

    private function setCoolifyVariables(): void
    {
        $config = $this->context->getDeploymentConfig();
        $applicationDeploymentQueue = $this->context->getApplicationDeploymentQueue();
        $application = $this->context->getApplication();
        $variables = collect();
        $variables->put('SOURCE_COMMIT', $config->getCommit());
        if ($applicationDeploymentQueue->pull_request_id !== 0) {
            $fqdn = $application->fqdn;
        } else {
            $fqdn = $config->getPreview()->fqdn;
        }

        if ($fqdn) {
            $variables->put('COOLIFY_FQDN', $fqdn);
            $hostname = str($fqdn)->replace('http://', '')
                ->replace('https://', '');

            $variables->put('COOLIFY_URL', $hostname);
        }

        if ($application->git_branch) {
            $variables->put('COOLIFY_BRANCH', $application->git_branch);
        }

        $config->setCoolifyVariables($variables);
    }

    private function runPreDeploymentCommand(): void
    {
        $application = $this->context->getApplication();
        $applicationDeploymentQueue = $this->context->getApplicationDeploymentQueue();
        if (empty($application->pre_deployment_command)) {
            return;
        }

        $containers = $this->getCurrentApplicationContainerStatus($application->id, $applicationDeploymentQueue->pull_request_id);

        if ($containers->count() === 0) {
            return;
        }

        $applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: 'Executing pre-deployment command (see debug log for output/errors).'));

        foreach ($containers as $container) {
            $containerName = $container->Names;
            // TODO: Implement pre-deployment command
            // @see: run_pre_deployment_command
        }
    }

    private function getCurrentApplicationContainerStatus(int $id, ?int $pullRequestId = null, ?bool $includePullrequests = false): Collection
    {
        $containers = collect();

        if ($this->context->getCurrentServer()->isSwarm()) {
            return $containers;
        }

        $containers = $this->context->getDockerHelper()->getContainersForCoolifyLabelId($id);

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

    public function addSimpleLog(string $log): void
    {
        $this->context->getApplicationDeploymentQueue()->addDeploymentLog(new DeploymentOutput(output: $log));
    }

    protected function checkImageLocallyOrRemote()
    {
        $imageNames = $this->generateDockerImageNames();

        $applicationDeploymentQueue = $this->context->getApplicationDeploymentQueue();
        $imageQueryCommand = "docker images -q {$imageNames['productionImageName']} 2>/dev/null";
        $this->context->getDeploymentHelper()->executeAndSave([
            new RemoteCommand($imageQueryCommand, hidden: true, save: self::LOCAL_IMAGE_FOUND),
        ], $applicationDeploymentQueue, $this->context->getDeploymentResult()->savedLogs);

        $imageFoundOutput = $this->context->getDeploymentResult()->savedLogs->get(self::LOCAL_IMAGE_FOUND);

        if (strlen($imageFoundOutput) === 0 && $this->context->getApplication()->docker_registry_image_name) {
            $this->context->getDeploymentHelper()->executeAndSave([
                new RemoteCommand("docker pull {$imageNames['productionImageName']}  2>/dev/null", hidden: true, ignoreErrors: true),
                new RemoteCommand($imageQueryCommand, hidden: true, save: self::LOCAL_IMAGE_FOUND),
            ], $applicationDeploymentQueue, $this->context->getDeploymentResult()->savedLogs);
        }
    }

    protected function shouldSkipBuild(): bool
    {
        $localImageFound = $this->context->getDeploymentResult()->savedLogs->get(self::LOCAL_IMAGE_FOUND);
        $localImageHasBeenFound = str($localImageFound)->isNotEmpty();
        $imageNames = $this->generateDockerImageNames();

        $application = $this->context->getApplication();

        if ($localImageHasBeenFound) {
            $isAdditionalServer = $this->context->getDeploymentConfig()->isThisAdditionalServer();

            if ($isAdditionalServer) {
                $this->addSimpleLog("Image found ({$imageNames['productionImageName']}) with the same Git Commit SHA. Build step skipped.");
                $this->generateComposeFile();
                $this->pushToDockerRegistry();
                $this->rollingUpdate();

                return true;
            }

            if ($application->isConfigurationChanged()) {
                $this->addSimpleLog("No configuration changed & image found ({$imageNames['productionImageName']}) with the same Git Commit SHA. Build step skipped.");
                $this->generateComposeFile();
                $this->pushToDockerRegistry();
                $this->rollingUpdate();

                return true;
            }

            $this->addSimpleLog('Configuration changed. Rebuilding image.');

            return false;
        }

        return false;
    }

    private function generateComposeFile()
    {
        $this->createWorkDir();

        $generator = new DockerComposeGenerator($this);
        $generator->generate();
    }

    public function getApplication(): Application
    {
        return $this->getContext()->getApplication();
    }

    private function createWorkDir()
    {
        // TODO: Extract to DeploymentDirectoryHelper or something. This class is getting too big.
        $buildServerConfig = $this->getContext()->getDeploymentConfig();

        $useBuildServer = $buildServerConfig['useBuildServer'];

        $configDir = $this->getContext()->getDeploymentConfig()->getConfigurationDir();

        $workDir = $this->getContext()->getDeploymentConfig()->getWorkDir();

        $queue = $this->getContext()->getApplicationDeploymentQueue();

        $createConfigDirCommand = "mkdir -p {$configDir}";

        if ($this->getContext()->getDeploymentConfig()->useBuildServer()) {
            $this->context->switchToOriginalServer();

            $this->context->getDeploymentHelper()->executeAndSave([
                new RemoteCommand($createConfigDirCommand),
            ], $queue, $this->context->getDeploymentResult()->savedLogs);

            $this->context->switchToBuildServer();
        }

        $this->context->getDeploymentHelper()->executeAndSave([
            new RemoteCommand(executeInDocker($queue->deployment_uuid, "mkdir -p {$workDir}")),
            new RemoteCommand("mkdir -p {$configDir}"),
        ], $queue, $this->context->getDeploymentResult()->savedLogs);

    }

    private function pushToDockerRegistry()
    {
        throw new DeploymentCommandFailedException('Not implemented');
    }

    private function rollingUpdate()
    {
        throw new DeploymentCommandFailedException('Not implemented');
    }
}
