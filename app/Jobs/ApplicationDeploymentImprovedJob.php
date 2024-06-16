<?php

namespace App\Jobs;

use App\Domain\Deployment\DeploymentOutput;
use App\Livewire\Project\Shared\Destination;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplicationDeploymentImprovedJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ApplicationDeploymentQueue $applicationDeploymentQueue;
    // Being set in handle method
    private DockerProvider $dockerProvider;
    private DeploymentProvider $deploymentProvider;

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
        $this->dockerProvider = $dockerProvider;
        $this->deploymentProvider = $deploymentProvider;

        $this->applicationDeploymentQueue->setInProgress();

        $server = $this->getServer();

        if (!$server->isFunctional()) {
            $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: 'Server is not functional.'));
            $this->fail('Server is not functional.');

            return;
        }

    }

    private function getDockerAddHosts(): string
    {
        $dockerHelper = $this->dockerProvider->forServer($this->getServer());

        $destination = $this->getDestination();

        $allContainers = $dockerHelper->getContainersInNetwork($destination->network);
    }

    private function getDestination(): Destination
    {
        return $this->applicationDeploymentQueue->destination;
    }

    private function getServer(): Server
    {
        $server = Server::find($this->applicationDeploymentQueue->server_id);

        return $server;
    }
}
