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

it('prefers the selected tag for docker image rollback deployments', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $rollbackProperty = $reflection->getProperty('rollback');
    $rollbackProperty->setAccessible(true);
    $rollbackProperty->setValue($job, true);

    $commitProperty = $reflection->getProperty('commit');
    $commitProperty->setAccessible(true);
    $commitProperty->setValue($job, 'e487515a098c8d4f69c74712f1d0cc86e483e890');

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, new Application([
        'docker_registry_image_tag' => '6de68657a0e794e163ea2275c54c293a93fe065a',
    ]));

    $previewTagProperty = $reflection->getProperty('dockerImagePreviewTag');
    $previewTagProperty->setAccessible(true);
    $previewTagProperty->setValue($job, null);

    $method = $reflection->getMethod('resolveDockerImageTag');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('e487515a098c8d4f69c74712f1d0cc86e483e890');
});

it('falls back to the application docker image tag for rollbacks without a selected tag', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $rollbackProperty = $reflection->getProperty('rollback');
    $rollbackProperty->setAccessible(true);
    $rollbackProperty->setValue($job, true);

    $commitProperty = $reflection->getProperty('commit');
    $commitProperty->setAccessible(true);
    $commitProperty->setValue($job, '');

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, new Application([
        'docker_registry_image_tag' => 'stable',
    ]));

    $previewTagProperty = $reflection->getProperty('dockerImagePreviewTag');
    $previewTagProperty->setAccessible(true);
    $previewTagProperty->setValue($job, null);

    $method = $reflection->getMethod('resolveDockerImageTag');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('stable');
});

it('falls back to latest for rollbacks without selected or configured tags', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $rollbackProperty = $reflection->getProperty('rollback');
    $rollbackProperty->setAccessible(true);
    $rollbackProperty->setValue($job, true);

    $commitProperty = $reflection->getProperty('commit');
    $commitProperty->setAccessible(true);
    $commitProperty->setValue($job, '');

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

it('falls back to the application docker image tag for non preview deployments', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $rollbackProperty = $reflection->getProperty('rollback');
    $rollbackProperty->setAccessible(true);
    $rollbackProperty->setValue($job, false);

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

    $rollbackProperty = $reflection->getProperty('rollback');
    $rollbackProperty->setAccessible(true);
    $rollbackProperty->setValue($job, false);

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

function makeDockerRegistryTagPushJob(int $pullRequestId, ?string $dockerRegistryImageTag): object
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, $pullRequestId);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, new Application([
        'docker_registry_image_tag' => $dockerRegistryImageTag,
    ]));

    return $job;
}

it('pushes the configured docker registry image tag for production deployments', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = makeDockerRegistryTagPushJob(
        pullRequestId: 0,
        dockerRegistryImageTag: 'latest',
    );

    $method = $reflection->getMethod('shouldPushDockerRegistryImageTag');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBeTrue();
});

it('skips the configured docker registry image tag for preview deployments', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = makeDockerRegistryTagPushJob(
        pullRequestId: 42,
        dockerRegistryImageTag: 'latest',
    );

    $method = $reflection->getMethod('shouldPushDockerRegistryImageTag');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBeFalse();
});

it('skips pushing a configured docker registry image tag when no tag is set', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = makeDockerRegistryTagPushJob(
        pullRequestId: 0,
        dockerRegistryImageTag: null,
    );

    $method = $reflection->getMethod('shouldPushDockerRegistryImageTag');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBeFalse();
});
