<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentDockerfileBaseAction;
use App\Domain\Deployment\DeploymentConfig;
use App\Domain\Deployment\Generators\DockerComposeGenerator;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Models\Application;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\Yaml\Yaml;

class DeployDockerComposeAction extends DeploymentDockerfileBaseAction
{
    public function run(): void
    {
        $application = $this->getApplication();

        if ($application->docker_compose_location) {
            $this->context->getDeploymentResult()->setDockerComposeLocation($application->docker_compose_location);
        }

        $this->setCustomBuildStartCommands($application);

        $pullRequestId = $this->getContext()->getApplicationDeploymentQueue()->pull_request_id;
        $isPullRequest = $pullRequestId !== 0;

        $server = $this->context->getCurrentServer();

        $customRepository = $this->getContext()->getCustomRepository();

        if (! $isPullRequest) {
            $this->addSimpleLog("Starting deployment of {$application->name} to {$server->name}.");
        } else {
            $this->addSimpleLog("Starting pull request (#{$pullRequestId}) deployment of {$customRepository['repository']}:{$application->git_branch} to {$server->name}.");
        }

        $this->prepareBuilderImage();
        $this->checkGitIfBuildNeeded();
        $this->cloneRepository();
        $this->cleanupGit();

        $config = $this->context->getDeploymentConfig();

        $generator = new DockerComposeGenerator($this);
        $generator->writeEnvironmentVariables();

        $this->prepareWriteDockerComposeFile($application, $pullRequestId, $config);

        $this->buildDockerCompose();

        $this->stopRunningContainer(force: true);

        $this->createNetwork();

        $this->startByDockerComposeFile();

    }

    private function startByDockerComposeFile(): void
    {
        $application = $this->getApplication();

        if ($application->settings->is_raw_compose_deployment_enabled) {
            $this->deployRawComposeDeployment();
        } else {
            $this->deployComposeDeployment();
        }

        $this->addSimpleLog('New container started');
    }

    private function deployComposeDeployment(): void
    {
        $customStartCommand = $this->getContext()->getDeploymentResult()->getDockerComposeCustomStartCommand();
        $deployment = $this->getContext()->getApplicationDeploymentQueue();
        $config = $this->getContext()->getDeploymentConfig();
        $result = $this->getContext()->getDeploymentResult();

        if ($customStartCommand) {
            $command = executeInDocker($deployment->deployment_uuid, "cd {$config->getBaseDir()} && {$customStartCommand}");
            $this->getContext()->getDeploymentHelper()
                ->executeAndSave([
                    new RemoteCommand($command, hidden: true),
                ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

        } else {
            $command = "{$config->getCoolifyVariablesAsKeyValueString()} docker compose --env-file {$config->getWorkDir()}/{$config->getEnvFileName()} --project-directory {$config->getWorkDir()} -f {$config->getWorkDir()}{$result->getDockerComposeLocation()} up -d";

            $this->getContext()->getDeploymentHelper()
                ->executeAndSave([
                    new RemoteCommand(executeInDocker($deployment->deployment_uuid, $command), hidden: true),
                ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);
        }

        $this->writeDeploymentConfiguration();
    }

    private function deployRawComposeDeployment(): void
    {
        $customStartCommand = $this->getContext()->getDeploymentResult()->getDockerComposeCustomStartCommand();
        $deployment = $this->getContext()->getApplicationDeploymentQueue();
        $config = $this->getContext()->getDeploymentConfig();
        $result = $this->getContext()->getDeploymentResult();

        $application = $this->getApplication();

        if ($customStartCommand) {
            $command = executeInDocker($deployment->deployment_uuid, "cd {$config->getWorkDir()} && {$customStartCommand}");
            $this->getContext()->getDeploymentHelper()
                ->executeAndSave([
                    new RemoteCommand($command, hidden: true),
                ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

            $this->writeDeploymentConfiguration();

        } else {
            $this->writeDeploymentConfiguration();

            $serverWorkdir = $application->workdir();

            $command = "{$config->getCoolifyVariablesAsKeyValueString()} docker compose --env-file {$config->getWorkDir()}/{$config->getEnvFileName()} --project-directory {$serverWorkdir} -f {$serverWorkdir}{$result->getDockerComposeLocation()} up -d";

            $this->getContext()->getDeploymentHelper()
                ->executeAndSave([
                    new RemoteCommand(executeInDocker($deployment->deployment_uuid, $command), hidden: true),
                ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

        }
    }

    private function createNetwork(): void
    {
        $networkId = $this->getApplication()->uuid;

        if ($this->context->getApplicationDeploymentQueue()->pull_request_id !== 0) {
            $networkId .= "-{$this->context->getApplicationDeploymentQueue()->pull_request_id}";
        }

        if ($this->context->getCurrentServer()->isSwarm()) {
            // TODO: Swarm not supported yet
        } else {
            $this->getContext()->getDeploymentHelper()
                ->executeAndSave([
                    new RemoteCommand("docker inspect '{$networkId}' >/dev/null 2>&1  || docker network create --attachable '{$networkId}'>/dev/null || true", hidden: true, ignoreErrors: true),
                    new RemoteCommand("docker network connect {$networkId} coolify-proxy || true", hidden: true, ignoreErrors: true),
                ], $this->getContext()->getApplicationDeploymentQueue(), $this->getContext()->getDeploymentResult()->savedLogs);
        }
    }

    private function buildDockerCompose(): void
    {
        $this->addSimpleLog('Pulling & building required images.');

        $deployment = $this->getContext()->getApplicationDeploymentQueue();
        $result = $this->context->getDeploymentResult();
        $config = $this->context->getDeploymentConfig();

        if ($result->getDockerComposeCustomBuildCommand()) {
            $commandToExecute = "cd {$config->getBaseDir()} && {$result->getDockerComposeCustomBuildCommand()}";
        } else {
            $commandToExecute = "{$config->getCoolifyVariablesAsKeyValueString()} docker compose --env-file {$config->getWorkDir()}/{$config->getEnvFileName()} --project-directory {$config->getWorkDir()} -f {$config->getWorkDir()}{$result->getDockerComposeLocation()} build";
        }

        $this->context->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand(executeInDocker($deployment->deployment_uuid, $commandToExecute), hidden: true),
            ], $deployment, $result->savedLogs);

    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        // TODO: Implement generateDockerImageNames() method.
    }

    private function addProjectConfig(string $command, string $workDir)
    {
        if (str($command)->contains('--project-directory')) {
            return str($command)->replaceFirst('compose', "compose --project-directory {$workDir}");
        }

        return $command;
    }

    private function setCustomBuildStartCommands(Application $application)
    {
        $config = $this->getContext()->getDeploymentConfig();
        $result = $this->getContext()->getDeploymentResult();

        if ($application->docker_compose_custom_start_command) {
            $startCommand = $this->addProjectConfig($application->docker_compose_custom_start_command, $config->getWorkDir());

            $result->setDockerComposeCustomStartCommand($startCommand);
        }

        if ($application->docker_compose_custom_build_command) {
            $buildCommand = $this->addProjectConfig($application->docker_compose_custom_build_command, $config->getWorkDir());

            $result->setDockerComposeCustomBuildCommand($buildCommand);
        }
    }

    private function prepareWriteDockerComposeFile(Application $application, int $pullRequestId, DeploymentConfig $config): void
    {
        $application->parseCompose();
        // TODO: Extract this logic to an Application service in the future.
        $application->loadComposeFile(isInit: false);

        if ($application->settings->is_raw_compose_deployment_enabled) {
            // TODO: Extract to a DockerComposeParser service in the future.
            $application->parseRawCompose();
            $yaml = $application->docker_compose_raw;
        } else {
            // TODO: Extract to a DockerComposeParser service in the future.
            $composeFile = $application->parseCompose(
                pull_request_id: $pullRequestId,
                preview_id: $this->getContext()->getDeploymentConfig()->getPreview()?->id
            );

            $services = collect($composeFile['services']);

            $services = $services->map(function ($service, $name) use ($config) {
                $service['env_file'] = $config->getEnvFileName();

                return $service;
            });

            $composeFile['services'] = $services->toArray();

            $yaml = Yaml::dump($composeFile->toArray());
        }

        $yamlBase64Encoded = base64_encode($yaml);

        $this->context->getDeploymentResult()->setDockerComposeBase64($yamlBase64Encoded);
        $this->writeDockerComposeFile();
    }
}
