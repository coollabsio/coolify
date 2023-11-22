<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Traits\ExecuteRemoteCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class ApplicationDeploySimpleDockerfileJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecuteRemoteCommand;

    public $timeout = 3600;
    public $tries = 1;
    public string $applicationDeploymentQueueId;

    public function __construct(string $applicationDeploymentQueueId)
    {
        $this->applicationDeploymentQueueId = $applicationDeploymentQueueId;
    }
    public function handle()
    {
        ray('Deploying Simple Dockerfile');
        $applicationDeploymentQueue = ApplicationDeploymentQueue::find($this->applicationDeploymentQueueId);
        $application = Application::find($applicationDeploymentQueue->application_id);
        $destination = $application->destination->getMorphClass()::where('id', $application->destination->id)->first();
        $server = data_get($destination, 'server');
        $commands = collect([]);
        $commands->push(
            [
                'command' => 'echo "Starting deployment of simple dockerfile."',
            ],
            [
                'command' => 'ls -la',
            ]
        );
        $server->executeRemoteCommand(commands: $commands, logModel: $applicationDeploymentQueue);
    }
}
