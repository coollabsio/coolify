<?php

namespace App\Jobs\Experimental;

use App\Actions\Docker\GetContainersStatus;
use App\Domain\Deployment\DeploymentAction\DeployDockerComposeAction;
use App\Domain\Deployment\DeploymentAction\DeployDockerfileAction;
use App\Domain\Deployment\DeploymentAction\DeployDockerImageAction;
use App\Domain\Deployment\DeploymentAction\DeployNixpacksAction;
use App\Domain\Deployment\DeploymentAction\DeploySimpleDockerfileAction;
use App\Domain\Deployment\DeploymentContext;
use App\Domain\Deployment\DeploymentOutput;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProcessStatus;
use App\Events\ApplicationStatusChanged;
use App\Exceptions\ExperimentalDeploymentJobException;
use App\Jobs\ApplicationPullRequestUpdateJob;
use App\Models\ApplicationDeploymentQueue;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
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

class ExperimentalDeploymentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ApplicationDeploymentQueue $applicationDeploymentQueue;

    private DeploymentContext $context;

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
        $this->context = new DeploymentContext($this->applicationDeploymentQueue, $dockerProvider, $deploymentProvider);
        $server = $this->context->getServerFromDeploymentQueue();

        if (! $server->isFunctional()) {
            $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: 'Server is not functional.'));

            throw new ExperimentalDeploymentJobException('Server is not functional.');
        }

        $this->applicationDeploymentQueue->setInProgress();

        try {
            $this->decideWhatToDo();
            $this->postDeployment();
        } catch (Exception $ex) {
            $application = $this->context->getApplication();
            if ($this->applicationDeploymentQueue->pull_request_id !== 0 && $application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $application, preview: $this->getPreview(), deployment_uuid: $this->applicationDeploymentQueue->deployment_uuid, status: ProcessStatus::IN_PROGRESS);
            }

            $this->fail($ex);

            throw $ex;
        } finally {
            //            $this->cleanUp();
            $application = $this->context->getApplication();
            ApplicationStatusChanged::dispatch($application->environment->project->team_id);
        }
    }

    private function postDeployment(): void
    {
        $server = $this->context->getServerFromDeploymentQueue();

        if ($server->isProxyShouldRun()) {
            GetContainersStatus::dispatch($server);
        }

        $this->handleNextDeployment(ApplicationDeploymentStatus::FINISHED);

        $application = $this->context->getApplication();

        if ($this->applicationDeploymentQueue->pull_request_id !== 0) {
            if ($application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $application, preview: $this->getPreview(), deployment_uuid: $this->applicationDeploymentQueue->deployment_uuid, status: ProcessStatus::FINISHED);
            }
        }

        $this->runPostDeploymentCommand();

        $application->isConfigurationChanged(true);
    }

    private function runPostDeploymentCommand(): void
    {
        $application = $this->context->getApplication();

        if (empty($application->post_deployment_command)) {
            return;
        }

        $this->fail('Post deployment command is not supported yet.');

        // @see run_post_deployment_command
    }

    private function decideWhatToDo(): void
    {
        if ($this->isRestartOnly()) {
            $this->actionRestart();

            return;
        }

        if ($this->applicationDeploymentQueue->pull_request_id !== 0) {
            $this->fail('Pull request deployment is not supported yet.');

            return;
        }

        $application = $this->context->getApplication();

        if ($application->dockerfile) {
            $this->actionDeploySimpleDockerfile();

            return;
        }

        if ($application->build_pack === 'dockercompose') {
            $this->actionDeployDockerCompose();

            return;
        }

        if ($application->build_pack === 'dockerimage') {
            $this->actionDeployDockerImage();

            return;
        }

        if ($application->build_pack === 'dockerfile') {
            $this->actionDeployDockerfile();

            return;
        }

        if ($application->build_pack === 'static') {
            $this->fail('Static deployment is not supported yet.');

            return;
        }

        $this->actionDeployNixpacks();
    }

    private function actionDeployDockerCompose(): void
    {
        // TODO: Refactor this so it's gets generated by a provider so we can mock it and make sure we can unit test this class.

        $dockerComposeAction = new DeployDockerComposeAction($this->context);
        $dockerComposeAction->run();
    }

    private function actionDeployDockerImage(): void
    {
        // @see deploy_dockerimage_buildpack
        $this->context->switchToBuildServer();

        // TODO: Refactor this so it's gets generated by a provider so we can mock it and make sure we can unit test this class.
        $dockerImageAction = new DeployDockerImageAction($this->context);
        $dockerImageAction->run();
    }

    private function actionDeployDockerfile(): void
    {
        // @see deploy_dockerfile_buildpack
        $this->context->switchToBuildServer();

        // TODO: Refactor this so it's gets generated by a provider so we can mock it and make sure we can unit test this class.
        $dockerfileAction = new DeployDockerfileAction($this->context);
        $dockerfileAction->run();
    }

    private function actionDeploySimpleDockerfile(): void
    {
        // @see deploy_simple_dockerfile
        $this->context->switchToBuildServer();

        // TODO: Refactor this so it's gets generated by a provider so we can mock it and make sure we can unit test this class.
        $simpleDockerfileAction = new DeploySimpleDockerfileAction($this->context);
        $simpleDockerfileAction->run();
    }

    private function actionDeployNixpacks(): void
    {
        // @see deploy_nixpacks_buildpack
        $this->context->switchToBuildServer();

        // TODO: Refactor this so it's gets generated by a provider so we can mock it and make sure we can unit test this class.
        $nixpacksAction = new DeployNixpacksAction($this->context);
        $nixpacksAction->run();
    }

    private function actionRestart(): void
    {
        //        $customRepository = $this->context->getCustomRepository();
        //        $application = $this->context->getApplication();
        //        $server = $this->context->getBuildServerSettings()['originalServer'];
        //        $this->addSimpleLog("Restarting {$customRepository['repository']}:{$application->git_branch} on {$server->name}.");
        //
        //        $restartAction = new DeploymentActionRestart($this->context);
        //
        //        $restartAction->run();
    }

    private function cleanUp(): void
    {
        $useBuildServer = $this->context->getDeploymentConfig()->useBuildServer();

        if ($useBuildServer === false) {
            // TODO: Enable this method and fix it.
            //$this->writeDeploymentConfiguration();
        }

        $this->dockerCleanupContainer();

    }

    private function isRestartOnly(): bool
    {
        $application = $this->context->getApplication();

        return $this->applicationDeploymentQueue->restart_only &&
            $application->build_pack !== 'dockerimage' &&
            $application->build_pack !== 'dockerfile';
    }

    private function dockerCleanupContainer(): void
    {
        $buildServerSettings = $this->context->getBuildServerSettings();

        $server = $buildServerSettings['useBuildServer'] ? $buildServerSettings['buildServer'] : $buildServerSettings['originalServer'];
        $deployment = $this->context->getDeploymentProvider()->forServer($server);

        $deployment->executeAndSave([
            new RemoteCommand("docker rm -f {$this->applicationDeploymentQueue->deployment_uuid} >/dev/null 2>&1", hidden: true, ignoreErrors: true),
        ], $this->applicationDeploymentQueue, $this->context->getDeploymentResult()->savedLogs);
    }

    private function getDockerAddHosts(): string
    {
        // TODO: Move this to Build Config
        $dockerHelper = $this->context->getDockerProvider()->forServer($this->context->getServerFromDeploymentQueue());

        $destination = $this->context->getDeploymentConfig()->getDestination();

        $allContainers = $dockerHelper->getContainersInNetwork($destination->network);

        $filteredContainers = $allContainers->exceptContainers(['coolify-proxy'])
            ->filterNotRegex('/-(\d{12})/');

        return $filteredContainers->getContainers()->map(function (DockerNetworkContainerInstanceOutput $container) {
            $name = $container->containerName();
            $ip = $container->ipv4WithoutMask();

            return "--add-host $name:$ip";
        })->implode(' ');
    }

    private function getBuildTarget(): ?string
    {
        $application = $this->context->getApplication();

        if (strlen($application->dockerfile_target_build) === 0) {
            return null;
        }

        return "--target {$application->dockerfile_target_build}";
    }

    private function handleNextDeployment(ApplicationDeploymentStatus $status)
    {

        $application = $this->context->getApplication();

        queue_next_deployment($application);

        $deployment = $this->context->getApplicationDeploymentQueue();

        if (
            $deployment->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value && $deployment->status !== ApplicationDeploymentStatus::FAILED->value
        ) {
            $deployment->setEnumStatus($status);

        }

        $config = $this->context->getDeploymentConfig();

        if ($deployment->status === ApplicationDeploymentStatus::FAILED->value) {
            $application->environment->project->team?->notify(new DeploymentFailed($application, $deployment->deployment_uuid, $config->getPreview()));

            return;
        }

        if ($status === ApplicationDeploymentStatus::FINISHED) {
            if (! $deployment->only_this_server) {
                $this->deployToAdditionalDestination();
            }
            $application->environment->project->team?->notify(new DeploymentSuccess($application, $deployment->deployment_uuid, $config->getPreview()));
        }
    }

    private function deployToAdditionalDestination(): void {}
}
