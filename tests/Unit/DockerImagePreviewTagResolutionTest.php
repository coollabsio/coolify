<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;

it('prefers the preview specific docker image tag for preview deployments', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 42);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, new Application([
        'docker_registry_image_tag' => 'latest',
    ]));

    $previewTagProperty = $reflection->getProperty('dockerImagePreviewTag');
    $previewTagProperty->setAccessible(true);
    $previewTagProperty->setValue($job, 'pr_42');

    $method = $reflection->getMethod('resolveDockerImageTag');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('pr_42');
});

it('falls back to the application docker image tag for non preview deployments', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, new Application([
        'docker_registry_image_tag' => 'stable',
    ]));

    $previewTagProperty = $reflection->getProperty('dockerImagePreviewTag');
    $previewTagProperty->setAccessible(true);
    $previewTagProperty->setValue($job, 'pr_42');

    $method = $reflection->getMethod('resolveDockerImageTag');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('stable');
});

it('falls back to latest when neither preview nor application tags are set', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 7);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, new Application([
        'docker_registry_image_tag' => '',
    ]));

    $previewTagProperty = $reflection->getProperty('dockerImagePreviewTag');
    $previewTagProperty->setAccessible(true);
    $previewTagProperty->setValue($job, null);

    $method = $reflection->getMethod('resolveDockerImageTag');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('latest');
});
