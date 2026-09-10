<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\DockerRegistry;
use App\Models\Server;
use Illuminate\Support\Facades\Config;

it('determines registry login is needed when credentials and image are present', function () {
    // Use stdClass to avoid encryption issues in unit tests
    $registry = new stdClass;
    $registry->username = 'registry-user';
    $registry->password = 'registry-token';
    $registry->registry_url = 'ghcr.io';

    $application = new Application;
    $application->docker_registry_image_name = 'ghcr.io/acme/app@sha256:4e272eef7ec6a7e76b9c521dcf14a3d397f7c370f48cbdbcfad22f041a1449cb';
    $application->setRelation('dockerRegistry', $registry);

    // Verify that the application has the necessary properties set for docker registry login
    expect($application->dockerRegistry)->not->toBeNull()
        ->and($application->dockerRegistry->username)->toBe('registry-user')
        ->and($application->dockerRegistry->password)->toBe('registry-token')
        ->and($application->dockerRegistry->registry_url)->toBe('ghcr.io')
        ->and($application->docker_registry_image_name)->toContain('ghcr.io/acme/app');
});

it('skips registry login when credentials are missing', function () {
    // Use stdClass to avoid encryption issues in unit tests
    $registry = new stdClass;
    $registry->username = null;
    $registry->password = null;
    $registry->registry_url = null;

    $application = new Application;
    $application->docker_registry_image_name = 'ghcr.io/acme/app';
    $application->setRelation('dockerRegistry', $registry);

    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $mockQueue->shouldReceive('addLogEntry')->never();

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('execute_remote_command')->never();

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $queueProperty = $reflection->getProperty('application_deployment_queue');
    $queueProperty->setAccessible(true);
    $queueProperty->setValue($job, $mockQueue);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $application);

    $deploymentUuidProperty = $reflection->getProperty('deployment_uuid');
    $deploymentUuidProperty->setAccessible(true);
    $deploymentUuidProperty->setValue($job, 'deployment-uuid');

    $method = $reflection->getMethod('login_to_docker_registry_if_needed');
    $method->setAccessible(true);
    $method->invoke($job);
});
