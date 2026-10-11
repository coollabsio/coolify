<?php

/**
 * Preview deployments of static build pack applications must build the image
 * from the generated nginx Dockerfile, not from a Dockerfile in the repository.
 *
 * @see https://github.com/coollabsio/coolify/issues/8238
 */

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;

it('builds static build pack preview images from the generated nginx Dockerfile', function () {
    $application = new Application;
    $application->build_pack = 'static';
    $application->static_image = 'nginx:alpine';
    $application->custom_nginx_configuration = 'server { listen 80; }';

    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry');

    $commands = [];
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldReceive('execute_remote_command')->andReturnUsing(function (...$batch) use (&$commands) {
        foreach ($batch as $command) {
            $commands[] = $command[0];
        }
    });

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'deployment_uuid' => 'deployment-uuid',
        'pull_request_id' => 7,
        'workdir' => '/artifacts/deployment-uuid',
        'production_image_name' => 'preview-app:pr-7-abc',
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'build_pull_request_image'))->invoke($job);

    $writesGeneratedDockerfile = collect($commands)
        ->contains(fn (string $command) => str_contains($command, 'tee /artifacts/deployment-uuid/Dockerfile'));

    expect($writesGeneratedDockerfile)->toBeTrue();
});
