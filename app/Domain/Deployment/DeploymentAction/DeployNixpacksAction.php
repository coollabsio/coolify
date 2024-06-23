<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentBaseAction;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Collection;
use JetBrains\PhpStorm\ArrayShape;
use RuntimeException;
use Yosymfony\Toml\Toml;

class DeployNixpacksAction extends DeploymentBaseAction
{
    private const NIXPACKS_PLAN = 'nixpacks_plan';

    private const NIXPACKS_TYPE = 'nixpacks_type';

    public function run(): void
    {

        $server = $this->context->getCurrentServer();
        $customRepository = $this->context->getCustomRepository();
        $application = $this->context->getApplication();

        $this->addSimpleLog("Starting deployment of {$customRepository['repository']}:{$application->git_branch} to {$server->name}.");
        $this->addSimpleLog('Starting DeployNixpacksAction::run');

        $this->prepareBuilderImage();
        $this->checkGitIfBuildNeeded();

        $deploymentConfig = $this->context->getDeploymentConfig();

        if (! $deploymentConfig->isForceRebuild()) {
            $this->checkImageLocallyOrRemote();
            if ($this->shouldSkipBuild()) {
                return;
            }
        }

        $this->cloneRepository();
        $this->cleanupGit();

        $this->generateNixpacksConfigs();
        $this->generateComposeFile();

        $this->buildImage();
        $this->rollingUpdate();
    }

    private function generateNixpacksConfigs(): void
    {
        $command = $this->generateNixpacksBuildCmd();
        $deploymentQueue = $this->getContext()->getApplicationDeploymentQueue();

        $result = $this->getContext()->getDeploymentResult();
        $workDir = $this->getContext()->getDeploymentConfig()->getWorkDir();

        $this->addSimpleLog("Generating nixpacks configuration with command: {$command}");

        $nixpacksDockerCommand = executeInDocker($deploymentQueue->deployment_uuid, $command);
        $nixpacksDetectCommand = executeInDocker($deploymentQueue->deployment_uuid, "nixpacks detect {$workDir}");

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($nixpacksDockerCommand, hidden: true, save: self::NIXPACKS_PLAN),
                new RemoteCommand($nixpacksDetectCommand, hidden: true, save: self::NIXPACKS_TYPE),
            ], $deploymentQueue, $result->savedLogs);

        $nixpacksTypeResult = $result->savedLogs->get(self::NIXPACKS_TYPE);

        if ($nixpacksTypeResult) {
            // How this ever can be false, I don't know.
            if (str($nixpacksTypeResult)->isEmpty()) {
                throw new RuntimeException('Nixpacks failed to detect the application type. Please check the documentation of Nixpacks: https://nixpacks.com/docs/providers');
            }
        }

        $nixpacksPlanResult = $result->savedLogs->get(self::NIXPACKS_PLAN);
        if (str($nixpacksPlanResult)->isEmpty()) {
            throw new RuntimeException('Nixpacks failed to generate the plan. Please check the documentation of Nixpacks: https://nixpacks.com/docs/providers');
        }

        $this->addSimpleLog("Found application type: {$nixpacksTypeResult}");
        $this->addSimpleLog("If you need further customization, please check the documentation of Nixpacks: https://nixpacks.com/docs/providers/{$nixpacksTypeResult}");

        $parsedPlan = Toml::parse($nixpacksPlanResult);

        $buildEnvVars = $this->generateBuildEnvVariables();

        $planVariables = collect($parsedPlan['variables']);

        $mergedEnvs = $buildEnvVars->merge($planVariables);

        $aptPkgs = data_get($parsedPlan, 'phases.setup.aptPkgs', []);

        if (count($aptPkgs) === 0) {
            $aptPkgs = ['curl', 'wget'];
        } else {
            if (! in_array('curl', $aptPkgs)) {
                $aptPkgs[] = 'curl';
            }

            if (! in_array('wget', $aptPkgs)) {
                $aptPkgs[] = 'wget';
            }
        }

        $parsedPlan['phases']['setup']['aptPkgs'] = $aptPkgs;

        $isLaravel = data_get($parsedPlan, 'variables.IS_LARAVEL', false);
        if ($isLaravel) {
            $variables = $this->getLaravelFinetuned();

            data_set($parsedPlan, 'variables.NIXPACKS_PHP_FALLBACK_PATH', $variables[0]->value);
            data_set($parsedPlan, 'variables.NIXPACKS_PHP_ROOT_DIR', $variables[1]->value);
        }

        $nixpacksPlanEncoded = json_encode($parsedPlan, JSON_PRETTY_PRINT);

        $this->getContext()->getDeploymentResult()
            ->setNixpacksPlanJson($nixpacksPlanEncoded);

        $this->addSimpleLog("Final Nixpacks plan: {$nixpacksPlanEncoded}", hidden: true);

    }

    private function getLaravelFinetuned(): array
    {
        $pullRequestId = $this->getContext()->getApplicationDeploymentQueue()->pull_request_id;

        $environmentVariables = $pullRequestId === 0 ?
            $this->getApplication()->environment_variables :
            $this->getApplication()->environment_variables_preview;

        $pathKey = 'NIXPACKS_PHP_FALLBACK_PATH';
        $rootDirKey = 'NIXPACKS_PHP_ROOT_DIR';
        $nixPacksPhpFallbackPath = $environmentVariables->where('key', $pathKey)->first();
        $nixPacksPhpRootDir = $environmentVariables->where('key', $rootDirKey)->first();

        if (! $nixPacksPhpFallbackPath) {
            $nixPacksPhpFallbackPath = new EnvironmentVariable();
            $nixPacksPhpFallbackPath->key = $pathKey;
            $nixPacksPhpFallbackPath->value = '/index.php';
            $nixPacksPhpFallbackPath->application_id = $this->getApplication()->id;
            $nixPacksPhpFallbackPath->save();
        }
        if (! $nixPacksPhpRootDir) {
            $nixPacksPhpRootDir = new EnvironmentVariable();
            $nixPacksPhpRootDir->key = $rootDirKey;
            $nixPacksPhpRootDir->value = '/app/public';
            $nixPacksPhpRootDir->application_id = $this->getApplication()->id;
            $nixPacksPhpRootDir->save();
        }

        return [$nixPacksPhpFallbackPath, $nixPacksPhpRootDir];
    }

    private function generateNixpacksBuildCmd(): string
    {
        $application = $this->getApplication();

        $workDir = $this->getContext()->getDeploymentConfig()->getWorkDir();

        $nixpacksEnvs = $this->generateNixpacksEnvVariables();
        $nixpacksEnvsAsString = $nixpacksEnvs->implode(' ');

        $nixpacksCommand = "nixpacks plan -f toml {$nixpacksEnvsAsString}";

        if ($application->build_command) {
            $nixpacksCommand .= " --build-cmd \"{$application->build_command}\"";
        }

        if ($application->start_command) {
            $nixpacksCommand .= " --start-cmd \"{$application->start_command}\"";
        }

        if ($application->install_command) {
            $nixpacksCommand .= " --install-cmd \"{$application->install_command}\"";
        }

        $nixpacksCommand .= " {$workDir}";

        return $nixpacksCommand;
    }

    private function generateNixpacksEnvVariables(): Collection
    {
        $envs = collect();
        $application = $this->getApplication();

        $pullRequestId = $this->getContext()->getApplicationDeploymentQueue()->pull_request_id;

        $variables = $pullRequestId === 0 ? $application->nixpacks_environment_variables : $application->nixpacks_environment_variables_preview;

        foreach ($variables as $env) {
            if (! is_null($env->real_value)) {
                $envs->push("-- env {$env->key}={$env->real_value}");
            }
        }

        return $envs;
    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        $application = $this->getApplication();
        $applicationDeploymentQueue = $this->getContext()->getApplicationDeploymentQueue();

        $commit = $applicationDeploymentQueue->commit;

        if ($application->docker_registry_image_name) {
            return [
                'buildImageName' => "{$application->docker_registry_image_name}:{$commit}-build",
                'productionImageName' => "{$application->docker_registry_image_name}:{$commit}",
            ];
        }

        return [
            'buildImageName' => "{$application->uuid}:{$commit}-build",
            'productionImageName' => "{$application->uuid}:{$commit}",
        ];
    }

    public function buildImage(): void
    {
        $this->addSimpleLog('----------------------------');
        $this->addSimpleLog('Running DeployNixpacksAction::buildImage()');

        $plan = $this->getContext()->getDeploymentResult()->getNixpacksPlanJson();
        if (str($plan)->isEmpty()) {
            throw new RuntimeException('Nixpacks plan is not generated - cannot continue.');
        }

        $deployment = $this->getContext()->getApplicationDeploymentQueue();

        $nixpacksPlanBase64 = base64_encode($plan);
        $dockerCommand = executeInDocker($deployment->deployment_uuid, "echo '{$nixpacksPlanBase64}' | base64 -d | tee /artifacts/thegameplan.json > /dev/null");

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($dockerCommand, hidden: true),
            ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

        $imageNames = $this->generateDockerImageNames();

        $workDir = $this->getContext()->getDeploymentConfig()->getWorkDir();
        $addHosts = $this->getContext()->getDeploymentConfig()->getAddHosts();

        $buildArgs = $this->generateBuildEnvVariables();

        $buildArgsAsString = $buildArgs->map(function ($value, $key) {
            return "--build-arg {$key}={$value}";
        })->implode(' ');

        $application = $this->getApplication();

        if ($this->getContext()->getDeploymentConfig()->isForceRebuild()) {
            $nixpacksBuildCommand = executeInDocker($deployment->deployment_uuid, "nixpacks build -c /artifacts/thegameplan.json --no-cache --no-error-without-start -n {$imageNames['buildImageName']} {$workDir} -o {$workDir}");
            $buildCommand = "docker build --no-cache {$addHosts} --network host -f {$workDir}/.nixpacks/Dockerfile {$buildArgsAsString} --progress plain -t {$imageNames['productionImageName']} {$workDir}";
        } else {
            $nixpacksBuildCommand = executeInDocker($deployment->deployment_uuid, "nixpacks build -c /artifacts/thegameplan.json --cache-key '{$application->uuid}' --no-error-without-start -n {$imageNames['buildImageName']} {$workDir} -o {$workDir}");
            $buildCommand = "docker build {$addHosts} --network host -f {$workDir}/.nixpacks/Dockerfile {$buildArgsAsString} --progress plain -t {$imageNames['productionImageName']} {$workDir}";
        }

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($nixpacksBuildCommand, hidden: true),
            ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

        $base64BuildCommand = base64_encode($buildCommand);

        $setBuildCommand = executeInDocker($deployment->deployment_uuid, "echo '{$base64BuildCommand}' | base64 -d | tee /artifacts/build.sh > /dev/null");
        $executeBuildCommand = executeInDocker($deployment->deployment_uuid, 'bash /artifacts/build.sh');

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($setBuildCommand, hidden: true),
                new RemoteCommand($executeBuildCommand, hidden: true),
            ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

        $removeGameplanCommand = executeInDocker($deployment->deployment_uuid, 'rm /artifacts/thegameplan.json');

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($removeGameplanCommand, hidden: true),
            ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

        $this->addSimpleLog('Building docker image completed');
    }
}
