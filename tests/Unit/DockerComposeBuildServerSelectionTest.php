<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Server;

/**
 * Regression coverage for Docker Compose deployments that use a build server.
 *
 * The helper container is prepared before repository checkout, and later
 * docker exec calls reuse the same deployment UUID. If the active server still
 * points at the deployment server when helper preparation starts, subsequent
 * workdir/build commands on the build server cannot find that container.
 */
it('switches helper preparation to the build server when build servers are enabled', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $serverProperty = $reflection->getProperty('server');
    $serverProperty->setAccessible(true);
    $serverProperty->setValue($job, new Server(['name' => 'mocky server']));

    $buildServerProperty = $reflection->getProperty('build_server');
    $buildServerProperty->setAccessible(true);
    $buildServerProperty->setValue($job, new Server(['name' => 'Build Server']));

    $useBuildServerProperty = $reflection->getProperty('use_build_server');
    $useBuildServerProperty->setAccessible(true);
    $useBuildServerProperty->setValue($job, true);

    $method = $reflection->getMethod('switchToBuildServerIfNeeded');
    $method->setAccessible(true);
    $method->invoke($job);

    expect($serverProperty->getValue($job))->toBe($buildServerProperty->getValue($job));
});

it('keeps the deployment server active when build servers are disabled', function () {
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $deploymentServer = new Server(['name' => 'mocky server']);

    $serverProperty = $reflection->getProperty('server');
    $serverProperty->setAccessible(true);
    $serverProperty->setValue($job, $deploymentServer);

    $buildServerProperty = $reflection->getProperty('build_server');
    $buildServerProperty->setAccessible(true);
    $buildServerProperty->setValue($job, new Server(['name' => 'Build Server']));

    $useBuildServerProperty = $reflection->getProperty('use_build_server');
    $useBuildServerProperty->setAccessible(true);
    $useBuildServerProperty->setValue($job, false);

    $method = $reflection->getMethod('switchToBuildServerIfNeeded');
    $method->setAccessible(true);
    $method->invoke($job);

    expect($serverProperty->getValue($job))->toBe($deploymentServer);
});
