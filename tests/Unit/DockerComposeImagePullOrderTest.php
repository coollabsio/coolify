<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;

class TestableDockerComposeImagePullDeploymentJob extends ApplicationDeploymentJob
{
    public array $recordedCommands = [];

    public array $commandServers = [];

    public bool $failCommands = false;

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
        $this->commandServers[] = $reflection->getProperty('server')->getValue($this);
        $this->recordedCommands[] = $commands;

        if ($this->failCommands) {
            throw new RuntimeException('Image pull failed.');
        }
    }
}

function makeDockerComposeImagePullJob(bool $useBuildServer = false): array
{
    $job = new TestableDockerComposeImagePullDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-uuid';
    $application->shouldReceive('workdir')->andReturn('/runtime/application');

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->once()->andReturnNull();

    $buildServer = new Server;
    $buildServer->id = 1;
    $mainServer = new Server;
    $mainServer->id = 2;

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'server' => $buildServer,
        'build_server' => $buildServer,
        'mainServer' => $mainServer,
        'use_build_server' => $useBuildServer,
        'workdir' => '/artifacts/application',
        'deployment_uuid' => 'deployment-uuid',
        'docker_compose_location' => '/docker-compose.yaml',
        'coolify_variables' => '',
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    return [$job, $reflection, $buildServer, $mainServer];
}

it('pulls image-only compose services before removing running containers', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');
    $methodStart = strpos($source, 'private function deploy_docker_compose_buildpack()');
    $methodEnd = strpos($source, 'private function pull_docker_compose_images()', $methodStart);
    $deploymentMethod = substr($source, $methodStart, $methodEnd - $methodStart);

    $pullPosition = strpos($deploymentMethod, '$this->pull_docker_compose_images();');
    $stopPosition = strpos($deploymentMethod, '$this->stop_running_container(force: true);');

    expect($pullPosition)->not->toBeFalse()
        ->and($stopPosition)->not->toBeFalse()
        ->and($pullPosition)->toBeLessThan($stopPosition);
});

it('aborts the deployment when pulling a compose image fails', function () {
    [$job, $reflection] = makeDockerComposeImagePullJob();
    $job->failCommands = true;

    $reflection->getMethod('pull_docker_compose_images')->invoke($job);
})->throws(RuntimeException::class, 'Image pull failed.');

it('pulls compose images on the runtime server when using a build server', function () {
    [$job, $reflection, $buildServer, $mainServer] = makeDockerComposeImagePullJob(useBuildServer: true);

    $reflection->getMethod('pull_docker_compose_images')->invoke($job);

    $command = $job->recordedCommands[0][0][0];

    expect($job->commandServers)->toHaveCount(1)
        ->and($job->commandServers[0])->toBe($mainServer)
        ->and($job->commandServers[0])->not->toBe($buildServer)
        ->and($command)->toContain('pull --ignore-buildable')
        ->and($command)->toContain('--env-file /runtime/application/.env');
});
