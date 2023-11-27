<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Traits\ExecuteRemoteCommandNew;
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
    public $remoteCommandOutputs = [];
    public Server $server;
    public string $containerName;

    public function __construct(public ApplicationDeploymentQueue $deploymentQueueEntry, public Application $application)
    {
    }
    public function handle()
    {
        // ray()->clearAll();
        ray('Deploying Docker Image');
        static::$batch_counter = 0;
        try {
            $deploymentUuid = data_get($this->deploymentQueueEntry, 'deployment_uuid');
            $pullRequestId = data_get($this->deploymentQueueEntry, 'pull_request_id');

            $this->server = data_get($this->application->destination, 'server');
            $network = data_get($this->application->destination, 'network');

            $dockerImage = data_get($this->application, 'docker_registry_image_name');
            $dockerImageTag = data_get($this->application, 'docker_registry_image_tag');

            $productionImageName = str("{$dockerImage}:{$dockerImageTag}");
            $this->containerName = generateApplicationContainerName($this->application, $pullRequestId);
            savePrivateKeyToFs($this->server);

            ray("echo 'Starting deployment of {$productionImageName}.'");

            $this->deploymentQueueEntry->update([
                'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);

            $this->deploymentQueueEntry->addLogEntry('Starting deployment of ' . $productionImageName);

            $this->server->executeRemoteCommand(
                commands: collect(
                    [
                        [
                            "name" => "ls",
                            "command" => 'ls -la',
                            "hidden" => true,
                        ],
                        [
                            "name" => "pwd",
                            "command" => 'pwd',
                            "hidden" => true,
                        ]
                    ],
                ),
                loggingModel: $this->deploymentQueueEntry
            );
            $this->server->executeRemoteCommand(
                commands: prepareHelperContainer($this->server, $network, $deploymentUuid),
                loggingModel: $this->deploymentQueueEntry
            );
            $this->server->executeRemoteCommand(
                commands: generateComposeFile(
                    deploymentUuid: $deploymentUuid,
                    server: $this->server,
                    network: $network,
                    application: $this->application,
                    containerName: $this->containerName,
                    imageName: $productionImageName,
                    pullRequestId: $pullRequestId
                ),
                loggingModel: $this->deploymentQueueEntry
            );
            $this->deploymentQueueEntry->addLogEntry('----------------------------------------');

            // Rolling update not possible
            if (count($this->application->ports_mappings_array) > 0) {
                $this->deploymentQueueEntry->addLogEntry('Application has ports mapped to the host system, rolling update is not supported.');
                $this->deploymentQueueEntry->addLogEntry('Stopping running container.');
                $this->server->stopApplicationRelatedRunningContainers($this->application->id, $this->containerName);
            } else {
                $this->deploymentQueueEntry->addLogEntry('Rolling update started.');
                // TODO
                $this->server->executeRemoteCommand(
                    commands: startNewApplication(application: $this->application, deploymentUuid: $deploymentUuid, loggingModel: $this->deploymentQueueEntry),
                    loggingModel: $this->deploymentQueueEntry
                );
                // $this->server->executeRemoteCommand(
                //     commands: healthCheckContainer(application: $this->application, containerName: $this->containerName , loggingModel: $this->deploymentQueueEntry),
                //     loggingModel: $this->deploymentQueueEntry
                // );

            }

            ray($this->remoteCommandOutputs);
            $this->deploymentQueueEntry->update([
                'status' => ApplicationDeploymentStatus::FINISHED->value,
            ]);
        } catch (Throwable $e) {
            $this->fail($e);
            throw $e;
        }
    }
    public function failed(Throwable $exception): void
    {
        $this->deploymentQueueEntry->addLogEntry('Oops something is not okay, are you okay? 😢', 'error');
        $this->deploymentQueueEntry->addLogEntry($exception->getMessage(), 'error');
        $this->deploymentQueueEntry->addLogEntry('Deployment failed. Removing the new version of your application.');

        $this->server->executeRemoteCommand(
            commands: removeOldDeployment($this->containerName),
            loggingModel: $this->deploymentQueueEntry
        );
        $this->deploymentQueueEntry->update([
            'status' => ApplicationDeploymentStatus::FAILED->value,
        ]);
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
