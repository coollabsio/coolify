<?php

namespace App\Domain\Deployment\DeploymentAction\Abstract;

use App\Domain\Remote\Commands\RemoteCommand;
use App\Models\EnvironmentVariable;

abstract class DeploymentDockerfileBaseAction extends DeploymentBaseAction
{
    private const DOCKERFILE = 'dockerfile';

    protected function addBuildEnvVariablesToDockerfile(): void
    {
        $deployment = $this->getContext()->getApplicationDeploymentQueue();

        $config = $this->getContext()->getDeploymentConfig();
        $result = $this->getContext()->getDeploymentResult();

        $dockerCommand = executeInDocker($deployment->deployment_uuid, "cat {$config->getWorkDir()}{$result->getDockerfileLocation()}");

        $savedLogs = $this->getContext()->getDeploymentResult()->savedLogs;
        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($dockerCommand, hidden: true, save: self::DOCKERFILE),
            ], $this->getContext()->getApplicationDeploymentQueue(), $savedLogs);

        $dockerFileLines = str($savedLogs->get(self::DOCKERFILE))->trim()->explode("\n");

        $environmentVariables = $this->getBuildEnvVariables();

        foreach ($environmentVariables as $env) {
            /** @var EnvironmentVariable $env */
            if ($env->is_multiline) {
                $dockerFileLines->splice(1, 0, "ARG {$env->key}");
            } else {
                $dockerFileLines->splice(1, 0, "ARG {$env->key}={$env->real_value}");
            }
        }

        $dockerfileBase64 = base64_encode($dockerFileLines->implode("\n"));

        $this->writeDockerfileBase64($dockerfileBase64, hidden: true);
    }

    protected function writeDockerfileBase64(string $dockerFileContents, bool $hidden = false): void
    {
        $deployment = $this->getContext()->getApplicationDeploymentQueue();
        $config = $this->getContext()->getDeploymentConfig();
        $dockerFileLocation = $this->getContext()->getDeploymentResult()->getDockerfileLocation();

        $executeCommand = executeInDocker($deployment->deployment_uuid, "echo '{$dockerFileContents}' | base64 -d | tee {$config->getWorkDir()}{$dockerFileLocation} > /dev/null");

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($executeCommand, hidden: $hidden),
            ], $this->getContext()->getApplicationDeploymentQueue(), $this->getContext()->getDeploymentResult()->savedLogs);
    }

    public function buildImage(): void
    {
        $this->addSimpleLog('-------------------------------');
        $this->addSimpleLog('Experimental Deployment: running DeploySimpleDockerfileAction::buildImage()');

        $this->addSimpleLog('Building docker image started.');
        $this->addSimpleLog('To check the current progress, click on Show Debug Logs.');

        $application = $this->getApplication();
        $deployment = $this->getContext()->getApplicationDeploymentQueue();

        $config = $this->getContext()->getDeploymentConfig();

        $result = $this->context->getDeploymentResult();
        $isForceRebuild = $config->isForceRebuild();

        $buildArgs = $this->generateBuildEnvVariables();

        $buildArgsWithBuildArgs = $buildArgs->map(function ($value, $key) {
            return "--build-arg {$key}={$value}";
        })->implode(' ');

        $dockerImageNames = $this->generateDockerImageNames();
        $dockerBaseCommand = "--pull {$config->getBuildTarget()} {$config->getAddHosts()} --network host -f {$config->getWorkDir()}{$result->getDockerfileLocation()} {$buildArgsWithBuildArgs} --progress plain -t {$dockerImageNames['productionImageName']} {$config->getWorkDir()}";

        $dockerBuildCommand = $isForceRebuild ?
            "docker build --no-cache {$dockerBaseCommand}" :
            "docker build {$dockerBaseCommand}";

        $buildCommandBase64 = base64_encode($dockerBuildCommand);

        $this->context->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand(executeInDocker($deployment->deployment_uuid, "echo '{$buildCommandBase64}' | base64 -d | tee /artifacts/build.sh > /dev/null"), hidden: true),
                new RemoteCommand(executeInDocker($deployment->deployment_uuid, 'bash /artifacts/build.sh'), hidden: true),
            ], $this->context->getApplicationDeploymentQueue(), $this->context->getDeploymentResult()->savedLogs);
    }
}
