<?php

namespace App\Jobs;

use App\Domain\Deployment\DeploymentAction\DeploymentActionRestart;
use App\Domain\Deployment\DeploymentOutput;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Enums\ProcessStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;
use App\Services\Docker\Output\DockerNetworkContainerInstanceOutput;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use JetBrains\PhpStorm\ArrayShape;

class ApplicationDeploymentImprovedJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ApplicationDeploymentQueue $applicationDeploymentQueue;

    // Being set in handle method
    private DockerProvider $dockerProvider;

    private DeploymentProvider $deploymentProvider;

    private DeploymentResult $deploymentResult;

    /**
     * Create a new job instance.
     */
    public function __construct(int $applicationDeploymentQueueId)
    {
        $this->applicationDeploymentQueue = ApplicationDeploymentQueue::find($applicationDeploymentQueueId);

    }

    /**
     * Execute the job.
     */
    public function handle(DockerProvider $dockerProvider, DeploymentProvider $deploymentProvider): void
    {
        $this->deploymentResult = new DeploymentResult();
        $this->deploymentResult->savedLogs = collect();
        $this->dockerProvider = $dockerProvider;
        $this->deploymentProvider = $deploymentProvider;

        $this->applicationDeploymentQueue->setInProgress();

        $server = $this->getServerFromDeploymentQueue();

        if (! $server->isFunctional()) {
            $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: 'Server is not functional.'));
            $this->fail('Server is not functional.');

            return;
        }

        try {
            $this->decideWhatToDo();
        } catch (Exception $ex) {
            $application = $this->getApplication();
            if ($this->applicationDeploymentQueue->pull_request_id !== 0 && $application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $application, preview: $this->getPreview(), deployment_uuid: $this->applicationDeploymentQueue->deployment_uuid, status: ProcessStatus::IN_PROGRESS);
            }

            $this->fail($ex);

            throw $ex;
        } finally {
            $this->cleanUp();
            $application = $this->getApplication();
            ApplicationStatusChanged::dispatch($application->environment->project->team_id);
        }
    }

    private function decideWhatToDo(): void
    {
        if ($this->isRestartOnly()) {
            $this->actionRestart();

            return;
        }
    }

    private function actionRestart(): void
    {
        $customRepository = $this->getCustomRepository();
        $application = $this->getApplication();
        $server = $this->getBuildServerSettings()['originalServer'];
        $this->addSimpleLog("Restarting {$customRepository['repository']}:{$application->git_branch} on {$server->name}.");

        $deploymentHelper = $this->deploymentProvider->forServer($server);
        $dockerHelper = $this->dockerProvider->forServer($server);
        $restartAction = new DeploymentActionRestart($this->applicationDeploymentQueue, $server, $application, $deploymentHelper, $dockerHelper);
    }

    private function cleanUp(): void
    {
        $buildServerConfig = $this->getBuildServerSettings();

        if ($buildServerConfig['useBuildServer'] === false) {
            $this->writeDeploymentConfiguration();
        }

        $this->dockerCleanupContainer();

    }

    private function isRestartOnly(): bool
    {
        $application = $this->getApplication();

        return $this->applicationDeploymentQueue->restart_only &&
            $application->build_pack !== 'dockerimage' &&
            $application->build_pack !== 'dockerfile';
    }

    private function writeDeploymentConfiguration(): void
    {
        $dockerComposeBase64 = $this->deploymentResult->dockerComposeBase64;
        if (! $dockerComposeBase64) {
            return;
        }

        $buildServerConfig = $this->getBuildServerSettings();

        $readme = generate_readme_file($this->getApplication()->name, $this->applicationDeploymentQueue->updated_at);

        $directories = $this->getDirectories();

        if ($this->applicationDeploymentQueue->pull_request_id === 0) {
            $composeFileName = $directories['configurationDir'].'/docker-compose.yml';
        } else {
            $composeFileName = $directories['configurationDir'].'/docker-compose-'.$this->applicationDeploymentQueue->pull_request_id.'.yml';
            $this->deploymentResult->dockerComposeLocation = "/docker-compose-pr-{$this->applicationDeploymentQueue->pull_request_id}.yml";
        }

        $deploymentHelper = $this->deploymentProvider->forServer($buildServerConfig['originalServer']);

        $configurationDirectory = $directories['configurationDir'];

        $deploymentHelper->executeAndSave([
            new RemoteCommand("mkdir -p {$configurationDirectory}"),
            new RemoteCommand("echo '{$dockerComposeBase64}' | base64 -d | tee $composeFileName > /dev/null"),
            new RemoteCommand("echo '{$readme}' > $configurationDirectory/README.md"),
        ], $this->applicationDeploymentQueue, $this->deploymentResult->savedLogs);
    }

    #[ArrayShape(['baseDir' => 'string', 'configurationDir' => 'string'])]
    private function getDirectories(): array
    {
        $application = $this->getApplication();

        $directories = [
            'baseDir' => $application->generateBaseDir($this->applicationDeploymentQueue->deployment_uuid),
            'configurationDir' => application_configuration_dir().'/'.$application->uuid,
        ];

        return $directories;
    }

    private function dockerCleanupContainer(): void
    {
        $buildServerSettings = $this->getBuildServerSettings();

        $server = $buildServerSettings['useBuildServer'] ? $buildServerSettings['buildServer'] : $buildServerSettings['originalServer'];
        $deployment = $this->deploymentProvider->forServer($server);

        $deployment->executeAndSave([
            new RemoteCommand("docker rm -f {$this->applicationDeploymentQueue->deployment_uuid} >/dev/null 2>&1", hidden: true, ignoreErrors: true),
        ], $this->applicationDeploymentQueue, $this->deploymentResult->savedLogs);
    }

    private function getPreview()
    {
        return $this->getApplication()->generate_preview_fqdn($this->applicationDeploymentQueue->pull_request_id);
    }

    private function getDockerAddHosts(): string
    {
        $dockerHelper = $this->dockerProvider->forServer($this->getServerFromDeploymentQueue());

        $destination = $this->getDestination();

        $allContainers = $dockerHelper->getContainersInNetwork($this->getDestination()->network);

        $filteredContainers = $allContainers->exceptContainers(['coolify-proxy'])
            ->filterNotRegex('/-(\d{12})/');

        return $filteredContainers->getContainers()->map(function (DockerNetworkContainerInstanceOutput $container) {
            $name = $container->containerName();
            $ip = $container->ipv4WithoutMask();

            return "--add-host $name:$ip";
        })->implode(' ');
    }

    #[ArrayShape(['repository' => 'string', 'port' => 'string'])]
    private function getCustomRepository(): array
    {
        $application = $this->getApplication();

        $customRepository = $application->customRepository();

        return $customRepository;
    }

    #[ArrayShape(['useBuildServer' => 'bool', 'buildServer' => Server::class, 'originalServer' => Server::class])]
    private function getBuildServerSettings(): array
    {
        $application = $this->getApplication();

        $originalServer = $this->getServerFromDeploymentQueue();
        $buildServerArray = [
            'useBuildServer' => false,
            'buildServer' => $originalServer,
            'originalServer' => $originalServer,
        ];

        if (! $application->settings->is_build_server_enabled) {
            return $buildServerArray;
        }

        $teamId = $application->environment->project->team_id;

        $buildServers = $this->getBuildServersForTamId($teamId);

        if ($buildServers->isEmpty()) {
            $this->addSimpleLog('No suitable build server found. Using the deployment server.');

            return $buildServerArray;
        }

        $randomBuildServer = $buildServers->random();
        $this->addSimpleLog("Found a suitable build server: {$randomBuildServer->name}");

        $buildServerArray['buildServer'] = $randomBuildServer;
        $buildServerArray['useBuildServer'] = true;

        return $buildServerArray;

    }

    private function addSimpleLog(string $log): void
    {
        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: $log));
    }

    private function getBuildServersForTamId(int $teamId)
    {
        return Server::buildServers($teamId)->get();
    }

    private function getBuildTarget(): ?string
    {
        $application = $this->getApplication();

        if (strlen($application->dockerfile_target_build) === 0) {
            return null;
        }

        return "--target {$application->dockerfile_target_build}";
    }

    private function getApplication(): Application
    {
        return Application::find($this->applicationDeploymentQueue->application_id);
    }

    private function getDestination(): StandaloneDocker|SwarmDocker
    {
        return $this->getServerFromDeploymentQueue()->destinations()->where('id', $this->applicationDeploymentQueue->destination_id)->first();

    }

    private function getServerFromDeploymentQueue(): Server
    {
        $server = Server::find($this->applicationDeploymentQueue->server_id);

        return $server;
    }
}

class DeploymentResult
{
    public ?string $dockerComposeBase64;

    public ?string $dockerComposeLocation;

    public Collection $savedLogs;
}
