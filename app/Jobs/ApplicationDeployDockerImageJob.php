<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Traits\ExecuteRemoteCommandNew;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ApplicationDeployDockerImageJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecuteRemoteCommandNew;

    public $timeout = 3600;
    public $tries = 1;
    public string $applicationDeploymentQueueId;

    public function __construct(string $applicationDeploymentQueueId)
    {
        $this->applicationDeploymentQueueId = $applicationDeploymentQueueId;
    }
    public function handle()
    {
        ray()->clearAll();
        ray('Deploying Docker Image');
        try {
            $applicationDeploymentQueue = ApplicationDeploymentQueue::find($this->applicationDeploymentQueueId);

            $deploymentUuid = data_get($applicationDeploymentQueue, 'deployment_uuid');
            $pullRequestId = data_get($applicationDeploymentQueue, 'pull_request_id');

            $application = Application::find($applicationDeploymentQueue->application_id)->firstOrFail();
            $server = data_get($application->destination, 'server');
            $network = data_get($application->destination, 'network');

            $dockerImage = data_get($application, 'docker_registry_image_name');
            $dockerImageTag = data_get($application, 'docker_registry_image_tag');

            $productionImageName = str("{$dockerImage}:{$dockerImageTag}");

            $containerName = generateApplicationContainerName($application, $pullRequestId);
            savePrivateKeyToFs($server);

            ray("echo 'Starting deployment of {$productionImageName}.'");

            $applicationDeploymentQueue->update([
                'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);
            $server->executeRemoteCommand(
                commands: prepareHelperContainer($server, $network, $deploymentUuid),
                loggingModel: $applicationDeploymentQueue
            );
            $server->executeRemoteCommand(
                commands: generateComposeFile(
                    deploymentUuid: $deploymentUuid,
                    server: $server,
                    network: $network,
                    application: $application,
                    containerName: $containerName,
                    imageName: $productionImageName,
                    pullRequestId: $pullRequestId
                ),
                loggingModel: $applicationDeploymentQueue
            );
            $server->executeRemoteCommand(
                commands: rollingUpdate(application: $application, deploymentUuid: $deploymentUuid),
                loggingModel: $applicationDeploymentQueue
            );
            $applicationDeploymentQueue->update([
                'status' => ApplicationDeploymentStatus::FINISHED->value,
            ]);
        } catch (Throwable $e) {
            $this->executeRemoteCommand(
                server: $server,
                logModel: $applicationDeploymentQueue,
                commands: [
                    "echo 'Oops something is not okay, are you okay? 😢'",
                    "echo '{$e->getMessage()}'",
                    "echo -n 'Deployment failed. Removing the new version of your application.'",
                    executeInDocker($deploymentUuid, "docker rm -f $containerName >/dev/null 2>&1"),
                ]
            );
            // $this->next(ApplicationDeploymentStatus::FAILED->value);
            throw $e;
        }
    }
    // private function next(string $status)
    // {
    //     // If the deployment is cancelled by the user, don't update the status
    //     if ($this->application_deployment_queue->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
    //         $this->application_deployment_queue->update([
    //             'status' => $status,
    //         ]);
    //     }
    //     queue_next_deployment($this->application);
    //     if ($status === ApplicationDeploymentStatus::FINISHED->value) {
    //         $this->application->environment->project->team->notify(new DeploymentSuccess($this->application, $this->deployment_uuid, $this->preview));
    //     }
    //     if ($status === ApplicationDeploymentStatus::FAILED->value) {
    //         $this->application->environment->project->team->notify(new DeploymentFailed($this->application, $this->deployment_uuid, $this->preview));
    //     }
    // }
}
