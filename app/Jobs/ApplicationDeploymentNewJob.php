<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Traits\ExecuteRemoteCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class ApplicationDeploymentNewJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecuteRemoteCommand;

    public $timeout = 3600;
    public $tries = 1;

    public static int $batch_counter = 0;
    public Server $mainServer;
    public $servers;
    public string $basedir;
    public string $workdir;

    public string $deploymentUuid;
    public int $pullRequestId = 0;

    // Git related
    public string $gitImportCommands;
    public ?string $gitType = null;
    public string $gitRepository;
    public string $gitBranch;
    public int $gitPort;
    public string $gitFullRepoUrl;

    public function __construct(public ApplicationDeploymentQueue $deployment, public Application $application)
    {
        $this->mainServer = data_get($this->application, 'destination.server');
        $this->deploymentUuid = data_get($this->deployment, 'deployment_uuid');
        $this->pullRequestId = data_get($this->deployment, 'pull_request_id', 0);
        $this->gitType = data_get($this->deployment, 'git_type');

        $this->basedir = $this->application->generateBaseDir($this->deploymentUuid);
        $this->workdir = $this->basedir . rtrim($this->application->base_directory, '/');
    }
    public function handle()
    {
        try {
            ray()->clearAll();
            $this->deployment->setStatus(ApplicationDeploymentStatus::IN_PROGRESS->value);

            $hostIpMappings = $this->mainServer->getHostIPMappings($this->application->destination->network);
            if ($this->application->dockerfile_target_build) {
                $buildTarget = " --target {$this->application->dockerfile_target_build} ";
            }

            // Get the git repository and port (custom port or default port)
            [
                'repository' => $this->gitRepository,
                'port' => $this->gitPort
            ] = $this->application->customRepository();

            // Get the git branch and git import commands
            [
                'commands' => $this->gitImportCommands,
                'branch' => $this->gitBranch,
                'fullRepoUrl' => $this->gitFullRepoUrl
            ] = $this->application->generateGitImportCommands($this->deploymentUuid, $this->pullRequestId, $this->gitType);

            $this->servers = $this->application->servers();

            if ($this->deployment->restart_only) {
                if ($this->application->build_pack === 'dockerimage') {
                    throw new \Exception('Restart only is not supported for docker image based deployments');
                }
                $this->deployment->addLogEntry("Starting deployment of {$this->application->name}.");
                $this->servers->each(function ($server) {
                    $this->deployment->addLogEntry("Restarting {$this->application->name} on {$server->name}.");
                    $this->restartOnly($server);
                });
            }
            $this->next(ApplicationDeploymentStatus::FINISHED->value);
        } catch (Throwable $exception) {
            $this->fail($exception);
        } finally {
            $this->servers->each(function ($server) {
                $this->deployment->addLogEntry("Cleaning up temporary containers on {$server->name}.");
                $server->executeRemoteCommand(
                    commands: collect([])->push([
                        "command" => "docker rm -f {$this->deploymentUuid}",
                        "hidden" => true,
                        "ignoreErrors" => true,
                    ]),
                    loggingModel: $this->deployment
                );
            });
        }
    }
    public function restartOnly(Server $server)
    {
        $server->executeRemoteCommand(
            commands: $this->application->prepareHelperImage($this->deploymentUuid),
            loggingModel: $this->deployment
        );

        $privateKey = data_get($this->application, 'private_key.private_key', null);
        $gitLsRemoteCommand = collect([]);
        if ($privateKey) {
            $privateKey = base64_decode($privateKey);
            $gitLsRemoteCommand
                ->push([
                    "command" => executeInDocker($this->deploymentUuid, "mkdir -p /root/.ssh")
                ])
                ->push([
                    "command" => executeInDocker($this->deploymentUuid, "echo '{$privateKey}' | base64 -d > /root/.ssh/id_rsa")
                ])
                ->push([
                    "command" => executeInDocker($this->deploymentUuid, "chmod 600 /root/.ssh/id_rsa")
                ])
                ->push([
                    "name" => "git_commit_sha",
                    "command" => executeInDocker($this->deploymentUuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->gitPort} -o Port={$this->gitPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git ls-remote {$this->gitFullRepoUrl} {$this->gitBranch}"),
                    "hidden" => true,
                ]);
        } else {
            $gitLsRemoteCommand->push([
                "name" => "git_commit_sha",
                "command" => executeInDocker($this->deploymentUuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->gitPort} -o Port={$this->gitPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git ls-remote {$this->gitFullRepoUrl} {$this->gitBranch}"),
                "hidden" => true,
            ]);
        }
        $this->deployment->addLogEntry("Checking if there is any new commit on {$this->gitBranch} branch.");

        $server->executeRemoteCommand(
            commands: $gitLsRemoteCommand,
            loggingModel: $this->deployment
        );
        $commit = str($this->deployment->getOutput('git_commit_sha'))->before("\t");

        [
            'productionImageName' => $productionImageName
        ] = $this->application->generateImageNames($commit, $this->pullRequestId);

        $this->deployment->addLogEntry("Checking if the image {$productionImageName} already exists.");
        $server->checkIfDockerImageExists($productionImageName, $this->deployment);

        if (str($this->deployment->getOutput('local_image_found'))->isNotEmpty()) {
            $this->deployment->addLogEntry("Image {$productionImageName} already exists. Skipping the build.");

            $server->createWorkDirForDeployment($this->workdir, $this->deployment);

            $this->application->generateDockerComposeFile($server, $this->deployment, $this->workdir);
            $this->application->rollingUpdateApplication($server, $this->deployment, $this->workdir);
            return;
        }
        throw new RuntimeException('Cannot find image anywhere. Please redeploy the application.');
    }
    public function failed(Throwable $exception): void
    {
        ray($exception);
        $this->next(ApplicationDeploymentStatus::FAILED->value);
    }
    private function next(string $status)
    {
        // If the deployment is cancelled by the user, don't update the status
        if ($this->deployment->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
            $this->deployment->update([
                'status' => $status,
            ]);
        }
        queue_next_deployment($this->application, isNew: true);
    }
}
