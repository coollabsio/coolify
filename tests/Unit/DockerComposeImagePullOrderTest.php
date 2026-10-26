<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
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

function makeDockerComposeImagePullJob(bool $useBuildServer = false, ?string $customBuildCommand = null, ?string $compose = null): array
{
    $compose ??= <<<'YAML'
        services:
          app:
            build: .
            image: 'myapp:latest'
          worker:
            image: 'myapp:latest'
          db:
            image: 'postgres:16'
          cache:
            image: 'redis:7'
        YAML;

    $job = new TestableDockerComposeImagePullDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-uuid';
    $application->name = 'application';
    $application->setRelation('settings', new ApplicationSetting(['is_raw_compose_deployment_enabled' => false]));
    $application->shouldReceive('workdir')->andReturn('/runtime/application');

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('getAttribute')->with('updated_at')->andReturn('2026-10-16 00:00:00');
    $queue->shouldReceive('addLogEntry')->andReturnNull();

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
        'configuration_dir' => '/runtime/application',
        'pull_request_id' => 0,
        'deployment_uuid' => 'deployment-uuid',
        'docker_compose_location' => '/docker-compose.yaml',
        'coolify_variables' => '',
        'docker_compose_custom_build_command' => $customBuildCommand,
        'generated_docker_compose' => $compose,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    return [$job, $reflection, $buildServer, $mainServer];
}

it('aborts the deployment when pulling a compose image fails', function () {
    [$job, $reflection] = makeDockerComposeImagePullJob();
    $job->failCommands = true;

    $reflection->getMethod('pull_docker_compose_images')->invoke($job);
})->throws(RuntimeException::class, 'Image pull failed.');

it('pulls compose images on the runtime server when using a build server', function () {
    [$job, $reflection, , $mainServer] = makeDockerComposeImagePullJob(useBuildServer: true);

    $reflection->getMethod('pull_docker_compose_images')->invoke($job);

    $command = last($job->recordedCommands)[0][0];

    expect($job->commandServers)->each->toBe($mainServer)
        ->and($command)->toContain('pull --ignore-buildable')
        ->and($command)->toContain('--env-file /runtime/application/.env');
});

it('pulls only services whose image does not come from the build', function () {
    [$job, $reflection] = makeDockerComposeImagePullJob();

    $reflection->getMethod('pull_docker_compose_images')->invoke($job);

    expect($job->recordedCommands)->toHaveCount(1)
        ->and($job->recordedCommands[0][0][0])->toEndWith("pull --ignore-buildable '\\''db'\\'' '\\''cache'\\'''");
});

it('does not pull when all images come from the build', function () {
    [$job, $reflection] = makeDockerComposeImagePullJob(compose: <<<'YAML'
        services:
          app:
            build: .
            image: 'myapp:latest'
          worker:
            image: 'myapp:latest'
        YAML);

    $reflection->getMethod('pull_docker_compose_images')->invoke($job);

    expect($job->recordedCommands)->toBeEmpty();
});

it('does not pull when a custom build command builds the images', function () {
    [$job, $reflection] = makeDockerComposeImagePullJob(customBuildCommand: 'docker buildx bake -f docker-bake.prod.hcl');

    $reflection->getMethod('pull_docker_compose_images')->invoke($job);

    expect($job->recordedCommands)->toBeEmpty();
});

it('pulls on the runtime server when a custom build command runs on a build server', function () {
    [$job, $reflection, , $mainServer] = makeDockerComposeImagePullJob(useBuildServer: true, customBuildCommand: 'docker buildx bake');

    $reflection->getMethod('pull_docker_compose_images')->invoke($job);

    expect($job->commandServers)->each->toBe($mainServer)
        ->and(last($job->recordedCommands)[0][0])->toEndWith("pull --ignore-buildable 'db' 'cache'");
});
