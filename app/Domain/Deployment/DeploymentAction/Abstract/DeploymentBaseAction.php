<?php

namespace App\Domain\Deployment\DeploymentAction\Abstract;

use App\Domain\Deployment\DeploymentContext;
use App\Domain\Deployment\DeploymentOutput;
use App\Domain\Deployment\Generators\DockerComposeGenerator;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Exceptions\DeploymentCommandFailedException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use JetBrains\PhpStorm\ArrayShape;
use RuntimeException;

abstract class DeploymentBaseAction
{
    private const LOCAL_IMAGE_FOUND = 'local_image_found';

    private const GIT_COMMIT_SHA = 'git_commit_sha';

    private const HEALTH_CHECK = 'health_check';

    private const HEALTH_CHECK_LOGS = 'health_check_logs';

    private const GIT_COMMIT_MESSAGE = 'git_commit_message';

    private const DOCKERFILE = 'dockerfile';

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

    abstract public function buildImage(): void;

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

    protected function cloneRepository(): void
    {
        $importCommands = $this->getContext()->generateGitImportCommands();

        $config = $this->getContext()->getDeploymentConfig();

        $application = $this->getApplication();
        $customRepository = $this->getContext()->getCustomRepository();
        $deploymentQueue = $this->getContext()->getApplicationDeploymentQueue();

        $this->addSimpleLog("\n----------------------------------------");
        $this->addSimpleLog("Importing {$customRepository['repository']}:{$application->git_branch} (commit sha {$application->git_commit_sha}) to {$config->getBaseDir()}.");

        if ($deploymentQueue->pull_request_id !== 0) {
            $this->addSimpleLog("Checking out tag pull/{$deploymentQueue->pull_request_id}/head.");
        }

        $command = $importCommands['commands'];

        $this->getContext()->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($command, hidden: true),
            ], $deploymentQueue, $this->getContext()->getDeploymentResult()->savedLogs);

        $this->createWorkDir();

        $gitCommand = executeInDocker($deploymentQueue->deployment_uuid, "cd {$config->getWorkDir()} && git log -1 {$deploymentQueue->commit} --pretty=%B");

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($gitCommand, hidden: true, save: self::GIT_COMMIT_MESSAGE),
            ], $deploymentQueue, $this->getContext()->getDeploymentResult()->savedLogs);

        if ($commitMessageFromLogs = $this->getContext()->getDeploymentResult()->savedLogs->get(self::GIT_COMMIT_MESSAGE)) {
            // I wonder how we never can end up here, but here we go.
            $commitMessage = str($commitMessageFromLogs)->limit(47);

            $deploymentQueue->commit_message = $commitMessage->value();

            ApplicationDeploymentQueue::whereCommit($deploymentQueue->commit)
                ->whereApplicationId($application->id)
                ->update(
                    ['commit_message' => $commitMessage->value()]
                );
        }
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

        if ($applicationDeploymentQueue->pull_request_id !== 0) {
            $localBranch = "pull/{$applicationDeploymentQueue->pull_request_id}/head";
        }

        $privateKey = $application->private_key?->private_key;

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
        if ($applicationDeploymentQueue->pull_request_id === 0) {
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

    public function addSimpleLog(string $log, bool $hidden = false): void
    {
        $this->context->getApplicationDeploymentQueue()->addDeploymentLog(new DeploymentOutput(output: $log, hidden: $hidden));
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

    protected function generateComposeFile()
    {
        $this->createWorkDir();

        $generator = new DockerComposeGenerator($this);
        $generator->generate();
    }

    public function getApplication(): Application
    {
        return $this->getContext()->getApplication();
    }

    protected function getBuildEnvVariables(): Collection
    {
        $application = $this->getApplication();
        $applicationEnvvars = $this->getContext()->getApplicationDeploymentQueue()->pull_request_id === 0 ?
           $application->build_environment_variables :
            $application->build_environment_variables_preview;

        return $applicationEnvvars;
    }

    protected function generateBuildEnvVariables(): Collection
    {
        $envs = collect();
        $envs->put('SOURCE_COMMIT', $this->getContext()->getApplicationDeploymentQueue()->commit);

        $applicationEnvvars = $this->getBuildEnvVariables();

        foreach ($applicationEnvvars as $env) {
            if (! is_null($env->real_value)) {
                $envs->put($env->key, $env->real_value);
            }
        }

        return $envs;
    }

    protected function pushToDockerRegistry(): void
    {
        // @see push_to_docker_registry
        $application = $this->getApplication();

        if (str($application->docker_registry_image_name)->isEmpty()) {
            return;
        }

        if ($this->getContext()->getDeploymentConfig()->isRestartOnly()) {
            return;
        }

        if ($application->build_pack === 'dockerimage') {
            return;
        }

        if ($this->getContext()->getDeploymentConfig()->isThisAdditionalServer()) {
            return;
        }

        $this->addSimpleLog('Pushing image to Docker registry.');

        $deployment = $this->getContext()->getApplicationDeploymentQueue();
        try {
            $buildNames = $this->generateDockerImageNames();

            $this->addSimpleLog('-----------------------------');
            $this->addSimpleLog("Pushing image to docker registry ({$buildNames['productionImageName']}).");

            $dockerPushCommand = executeInDocker($deployment->deployment_uuid, "docker push {$buildNames['productionImageName']}");

            $this->getContext()->getDeploymentHelper()->executeAndSave([
                new RemoteCommand($dockerPushCommand, hidden: true),
            ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

            if ($application->docker_registry_image_tag) {
                $this->addSimpleLog("Tagging and pushing image with {$application->docker_registry_image_tag} tag.");

                $tagCommand = executeInDocker($deployment->deployment_uuid, "docker tag {$buildNames['productionImageName']} {$application->docker_registry_image_name}:{$application->docker_registry_image_tag}");
                $pushCommand = executeInDocker($deployment->deployment_uuid, "docker push {$application->docker_registry_image_name}:{$application->docker_registry_image_tag}");

                $this->getContext()->getDeploymentHelper()->executeAndSave([
                    new RemoteCommand($tagCommand, hidden: true, ignoreErrors: true),
                    new RemoteCommand($pushCommand, hidden: true, ignoreErrors: true),
                ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);
            }
        } catch (Exception $e) {
            $this->addSimpleLog('Failed to push image to docker registry. Please check debug logs for more information.');

            throw new RuntimeException($e->getMessage(), 69420);
        }

    }

    private function createWorkDir()
    {
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

    protected function cleanupGit(): void
    {
        $baseDir = $this->getContext()->getDeploymentConfig()->getBaseDir();
        $applicationDeploymentQueue = $this->getContext()->getApplicationDeploymentQueue();
        $command = executeInDocker($applicationDeploymentQueue->deployment_uuid, "rm -rf {$baseDir}/.git");
        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($command),
            ], $applicationDeploymentQueue, $this->getContext()->getDeploymentResult()->savedLogs);
    }

    protected function rollingUpdate(): void
    {
        $deployment = $this->getContext()->getApplicationDeploymentQueue();
        $application = $this->getApplication();

        $config = $this->getContext()->getDeploymentConfig();
        $result = $this->getContext()->getDeploymentResult();

        if ($this->getContext()->getCurrentServer()->isSwarm()) {
            $this->addSimpleLog('Rolling update started (swam).');

            $workDir = $config->getWorkDir();
            $dockerComposeLocation = $result->getDockerComposeLocation();
            $swarmCommand = executeInDocker($deployment->deployment_uuid, "docker stack deploy --with-registry-auth -c {$workDir}{$dockerComposeLocation} {$application->uuid}");

            $this->getContext()->getDeploymentHelper()->executeAndSave([
                new RemoteCommand($swarmCommand, hidden: true),
            ], $deployment, $result->savedLogs);

            $this->addSimpleLog('Rolling update finished (swam).');

            return;
        }

        if ($config->useBuildServer()) {
            $this->writeDeploymentConfiguration();
            $this->context->switchToOriginalServer();
        }

        $rollingUpdateSupported = true;

        if (count($application->ports_mappings_array) > 0) {
            $this->addSimpleLog('Application has ports mapped to the host system, rolling update is not supported.');
            $rollingUpdateSupported = false;
        }

        if ($application->settings->is_consistent_container_name_enabled) {
            $this->addSimpleLog('Consistent container name feature enabled, rolling update is not supported.');
            $rollingUpdateSupported = false;
        }

        if (isset($application->settings->custom_internal_name)) {
            $this->addSimpleLog('Custom internal name set, rolling update is not supported.');
            $rollingUpdateSupported = false;
        }

        if ($deployment->pull_request_id !== 0) {
            $this->addSimpleLog('Pull request deployment, rolling update is not supported.');
            $rollingUpdateSupported = false;
        }

        if (str($application->custom_docker_run_options)->contains(['--ip', '--ip6'])) {
            $this->addSimpleLog('Custom IP address is set, rolling update is not supported.');
            $rollingUpdateSupported = false;
        }

        if (! $rollingUpdateSupported) {
            $this->stopRunningContainer(force: true);
            $this->startByComposeFile();

            return;
        }

        $this->addSimpleLog('--------------------------');
        $this->addSimpleLog('Rolling update started.');
        $this->startByComposeFile();
        $this->healthCheck();
        $this->stopRunningContainer();
        $this->addSimpleLog('Rolling update finished.');
    }

    protected function healthCheck(): void
    {
        $server = $this->context->getCurrentServer();
        if ($server->isSwarm()) {
            // no health check for swarm yet.
            return;
        }

        $application = $this->getApplication();

        $config = $this->context->getDeploymentConfig();
        $result = $this->context->getDeploymentResult();

        if ($application->isHealthcheckDisabled() && $application->custom_healthcheck_found === false) {
            $result->setNewVersionHealthy(true);

            return;
        }

        if ($application->custom_healthcheck_found) {
            $this->addSimpleLog('Custom healthcheck found, skipping default healthcheck.');
        }

        $containerName = $config->getContainerName();

        $this->addSimpleLog('Waiting for healthcheck to pass on the new container.');

        $healthCheckOptions = $this->generateHealthCheckCommand();

        $sleepTime = $application->health_check_start_period;

        Sleep::for($sleepTime)->seconds();

        $counter = 1;

        while ($counter <= $application->health_check_retries) {
            $this->context->getDeploymentHelper()
                ->executeAndSave([
                    new RemoteCommand("docker inspect --format='{{json .State.Health.Status}}' {$containerName}", hidden: true, save: self::HEALTH_CHECK),
                    new RemoteCommand("docker inspect --format='{{json .State.Health.Log}}' {$containerName}", hidden: true, save: self::HEALTH_CHECK_LOGS),
                ], $this->context->getApplicationDeploymentQueue(), $result->savedLogs);

            $healthCheckStatus = $result->savedLogs->get(self::HEALTH_CHECK);
            $healthCheckLogs = $result->savedLogs->get(self::HEALTH_CHECK_LOGS);
            $this->addSimpleLog("Attempt {$counter} of {$application->health_check_retries} | Healthcheck status: {$healthCheckStatus}");

            $healthCheckOutput = str($healthCheckStatus)->replace('"', '')->value();

            $lastHealthLog = collect(json_decode($healthCheckLogs))->last();

            $lastHealthLogOutput = data_get(
                $lastHealthLog, 'Output', $defaultLogOutput = '(no logs)'
            );

            $lastHealthLogExitCode = data_get(
                $lastHealthLog, 'ExitCode', $defaultLogExitCode = '(no return code)'
            );

            if ($lastHealthLogOutput !== $defaultLogOutput || $lastHealthLogExitCode !== $defaultLogExitCode) {
                $this->addSimpleLog("Healthcheck logs: {$lastHealthLogOutput} | Return code: {$lastHealthLogExitCode}");
            }

            if ($healthCheckOutput === 'healthy') {
                $result->setNewVersionHealthy(true);
                $application->setStatus('running');
                $this->addSimpleLog('New container is healthy.');
                break;
            }

            if ($healthCheckOutput === 'unhealthy') {
                $result->setNewVersionHealthy(false);
                $this->saveContainerLogs($containerName);
                break;
            }

            $counter++;

            Sleep::for($application->health_check_interval)->seconds();
        }

        if (str($result->savedLogs->get(self::HEALTH_CHECK)->replace('"', ''))->value() === 'starting') {
            $this->saveContainerLogs($containerName);
        }

    }

    private function saveContainerLogs(string $containerName): void
    {
        // @see query_logs
        $this->addSimpleLog('-------------------------------------');
        $this->addSimpleLog('Container logs:');
        $this->context->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand("docker logs -n 100 {$containerName}", ignoreErrors: true, type: 'stderr'),
            ], $this->context->getApplicationDeploymentQueue(), $this->context->getDeploymentResult()->savedLogs);

    }

    #[ArrayShape(['fullHealthCheckUrl' => 'string', 'command' => 'string'])]
    private function generateHealthCheckCommand(): array
    {
        $application = $this->getApplication();

        $healthCheckPort = $application->health_check_port ?
            $application->health_check_port :
            $application->ports_exposes_array[0];

        if ($application->settings->is_static || $application->build_pack === 'static') {
            $healthCheckPort = 80;
        }

        if ($application->health_check_path) {
            $healthCheckUrl = "{$application->health_check_method}: {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}{$application->health_check_path}";
            $healthCheckCommand = [
                "curl -s -X {$application->health_check_method} -f {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}{$application->health_check_path} > /dev/null || wget -q -O- {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}{$application->health_check_path} > /dev/null || exit 1",
            ];
        } else {
            $healthCheckUrl = "{$application->health_check_method}: {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}/";
            $healthCheckCommand = [
                "curl -s -X {$application->health_check_method} -f {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}/ > /dev/null || wget -q -O- {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}/ > /dev/null || exit 1",
            ];
        }

        return [
            'fullHealthCheckUrl' => $healthCheckUrl,
            'command' => implode(' ', $healthCheckCommand),
        ];
    }

    protected function startByComposeFile(): void
    {
        $application = $this->getApplication();
        $deployment = $this->context->getApplicationDeploymentQueue();

        $result = $this->context->getDeploymentResult();
        $config = $this->context->getDeploymentConfig();

        $dockerComposeLocation = $result->getDockerComposeLocation();

        $coolifyVariablesAsString = $config->getCoolifyVariablesAsKeyValueString();

        if ($application->build_pack === 'dockerimage') {
            $this->addSimpleLog('Pulling latest images from the registry');

            $this->context->getDeploymentHelper()->executeAndSave([
                new RemoteCommand(executeInDocker($deployment->deployment_uuid, "docker compose --project-directory {$config->getWorkDir()} pull"), hidden: true),
                new RemoteCommand(executeInDocker($deployment->deployment_uuid, "{$coolifyVariablesAsString} docker compose --project-directory {$config->getWorkDir()} up --build -d"), hidden: true),
            ], $this->context->getApplicationDeploymentQueue(), $result->savedLogs);
        } else {
            if ($config->useBuildServer()) {
                $this->context->getDeploymentHelper()
                    ->executeAndSave([
                        new RemoteCommand("{$coolifyVariablesAsString} docker compose --project-directory {$config->getConfigurationDir()} -f {$config->getConfigurationDir()}{$dockerComposeLocation} up --build -d", hidden: true),
                    ], $deployment, $result->savedLogs);
            } else {
                $this->context->getDeploymentHelper()
                    ->executeAndSave([
                        new RemoteCommand(executeInDocker($deployment->deployment_uuid, "{$coolifyVariablesAsString} docker compose --project-directory {$config->getWorkDir()} -f {$config->getWorkDir()}{$dockerComposeLocation} up --build -d"), hidden: true),
                    ], $deployment, $result->savedLogs);

            }
        }

        $this->addSimpleLog('New container started.');
    }

    protected function stopRunningContainer(bool $force = false): void
    {
        $this->addSimpleLog('Removing old containers.');

        $result = $this->context->getDeploymentResult();
        $newVersionIsHealthy = $result->isNewVersionHealth();
        $deployment = $this->getContext()->getApplicationDeploymentQueue();
        $config = $this->context->getDeploymentConfig();

        $application = $this->getApplication();
        $pullRequestId = $deployment->pull_request_id;

        if ($newVersionIsHealthy || $force) {
            // TODO: Refactor to DockerHelper or something
            $containers = getCurrentApplicationContainerStatus($this->context->getCurrentServer(), $application->id, $this->context->getApplicationDeploymentQueue()->pull_request_id);

            if ($pullRequestId === 0) {
                // TODO: I dont know why we already know its 0 and still check if it's deployed as such
                $containers = $containers->filter(function ($container) use ($config, $pullRequestId) {
                    return data_get($container, 'Names') !== $config->getContainerName() && data_get($container, 'Names') !== $config->getContainerName().'-pr-'.$pullRequestId;
                });
            }

            $containers->each(function ($container) use ($deployment, $result) {
                $containerName = data_get($container, 'Names');

                $this->context->getDeploymentHelper()->executeAndSave([
                    new RemoteCommand("docker rm -f {$containerName}", hidden: true, ignoreErrors: true),
                ], $deployment, $result->savedLogs);
            });

            if ($application->settings->is_consistent_container_name_enabled || isset($application->settings->custom_internal_name)) {
                // TODO: I feel that this already should've happened in the code above
                $this->context->getDeploymentHelper()->executeAndSave([
                    new RemoteCommand("docker rm -f {$config->getContainerName()}", hidden: true, ignoreErrors: true),
                ], $deployment, $result->savedLogs);
            }

            return;
        }

        if ($application->dockerfile || $application->build_pack === 'dockerfile' || $application->build_pack === 'dockerimage') {
            $this->addSimpleLog('----------------------------------------');
            $this->addSimpleLog("WARNING: Dockerfile or Docker Image based deployment detected. The healthcheck needs a curl or wget command to check the health of the application. Please make sure that it is available in the image or turn off healthcheck on Coolify's UI.");
            $this->addSimpleLog('----------------------------------------');
        }

        $this->addSimpleLog('New container is not healthy, rolling back to the old container.');
        $deployment->setFailed();

        $this->context->getDeploymentHelper()->executeAndSave([
            new RemoteCommand("docker rm -f {$config->getContainerName()}", hidden: true, ignoreErrors: true),
        ], $deployment, $result->savedLogs);
    }

    protected function writeDeploymentConfiguration(): void
    {
        $dockerComposeBase64 = $this->context->getDeploymentResult()->getDockerComposeBase64();
        if (! $dockerComposeBase64) {
            return;
        }

        if ($this->context->getDeploymentConfig()->useBuildServer()) {
            $this->context->switchToOriginalServer();
        }

        $pullRequestId = $this->getContext()->getApplicationDeploymentQueue()->pull_request_id;

        $config = $this->getContext()->getDeploymentConfig();

        $readme = generate_readme_file($this->getApplication()->name, $this->getContext()->getApplicationDeploymentQueue()->updated_at);

        $composeFileName = $pullRequestId === 0 ?
            "{$config->getConfigurationDir()}/docker-compose.yml" :
            "{$config->getConfigurationDir()}/docker-compose-pr-{$pullRequestId}.yml";

        $this->getContext()->getDeploymentResult()->setDockerComposeLocation($composeFileName);

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand("mkdir -p {$config->getConfigurationDir()}"),
                new RemoteCommand("echo '{$dockerComposeBase64}' | base64 -d | tee $composeFileName > /dev/null"),
                new RemoteCommand("echo '{$readme}' > {$config->getConfigurationDir()}/README.md"),
            ], $this->getContext()->getApplicationDeploymentQueue(), $this->getContext()->getDeploymentResult()->savedLogs);

        if ($this->context->getDeploymentConfig()->useBuildServer()) {
            $this->context->switchToBuildServer();
        }
    }

    protected function writeDockerComposeFile(): void
    {
        $base64DockerCompose = $this->context->getDeploymentResult()->getDockerComposeBase64();

        $deployment = $this->context->getApplicationDeploymentQueue();
        $result = $this->context->getDeploymentResult();
        $config = $this->context->getDeploymentConfig();
        $command = executeInDocker($deployment->deployment_uuid, "echo '{$base64DockerCompose}' | base64 -d | tee {$config->getWorkDir()}/{$result->getDockerComposeLocation()} > /dev/null");

        //dd($command);
        $this->getContext()->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($command, hidden: true),
            ], $deployment, $result->savedLogs);
    }
}
