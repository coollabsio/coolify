<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Services\DockerBuildCacheConfiguration;

class TestableDockerBuildCacheDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}
}

function dockerBuildCacheDeploymentConfiguration(string $failurePolicy = 'continue'): DockerBuildCacheConfiguration
{
    return DockerBuildCacheConfiguration::fromArray([
        'enabled' => true,
        'cache_from' => ['type' => 'registry', 'value' => 'registry.example.com/team/app:buildcache'],
        'cache_to' => ['type' => 'registry', 'value' => 'registry.example.com/team/app:buildcache'],
        'failure_policy' => $failurePolicy,
    ], 'production', 'production');
}

function localDockerBuildCacheDeploymentConfiguration(): DockerBuildCacheConfiguration
{
    return DockerBuildCacheConfiguration::fromArray([
        'enabled' => true,
        'cache_from' => ['type' => 'raw', 'value' => 'type=local,src=/cache'],
        'cache_to' => ['type' => 'raw', 'value' => 'type=local,dest=/cache,mode=max'],
        'failure_policy' => 'continue',
    ], 'production', 'production');
}

function makeDockerBuildCacheDeploymentJob(
    DockerBuildCacheConfiguration $configuration,
    bool $forceRebuild = false,
): array {
    $job = new TestableDockerBuildCacheDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'dockerBuildCacheConfiguration' => $configuration,
        'force_rebuild' => $forceRebuild,
    ] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    return [$job, $reflection];
}

function invokeDockerBuildCacheCommand(object $job, ReflectionClass $reflection, string $command): string
{
    $method = $reflection->getMethod('docker_build_cache_command');
    $method->setAccessible(true);

    return $method->invoke($job, $command);
}

test('Dockerfile cache converts a Docker build into a named buildx build', function () {
    [$job, $reflection] = makeDockerBuildCacheDeploymentJob(dockerBuildCacheDeploymentConfiguration('fail'));

    $command = invokeDockerBuildCacheCommand(
        $job,
        $reflection,
        'DOCKER_BUILDKIT=1 docker build --progress plain -t app:latest /artifacts/app',
    );

    expect($command)
        ->toContain('docker buildx inspect coolify-docker-cache')
        ->toContain('docker buildx create --name coolify-docker-cache --driver docker-container')
        ->toContain('docker buildx build --builder coolify-docker-cache --load')
        ->toContain('--cache-from type=registry,ref=registry.example.com/team/app:buildcache')
        ->toContain('--cache-to type=registry,ref=registry.example.com/team/app:buildcache,mode=max')
        ->not->toContain('|| {');
});

test('continue policy retries the original build without external cache', function () {
    [$job, $reflection] = makeDockerBuildCacheDeploymentJob(dockerBuildCacheDeploymentConfiguration());
    $original = 'docker build --progress plain -t app:latest /artifacts/app';

    $command = invokeDockerBuildCacheCommand($job, $reflection, $original);

    expect($command)
        ->toContain('External Docker build cache failed; retrying without it.')
        ->toContain('|| {')
        ->toEndWith($original.'; }');
});

test('force rebuild omits cache import and still exports cache', function () {
    [$job, $reflection] = makeDockerBuildCacheDeploymentJob(
        dockerBuildCacheDeploymentConfiguration('fail'),
        forceRebuild: true,
    );

    $command = invokeDockerBuildCacheCommand(
        $job,
        $reflection,
        'DOCKER_BUILDKIT=1 docker build --no-cache -t app:latest /artifacts/app',
    );

    expect($command)
        ->not->toContain('--cache-from')
        ->toContain('--cache-to type=registry,ref=registry.example.com/team/app:buildcache,mode=max')
        ->toContain('--no-cache');
});

test('local cache configuration mounts a persistent per-application directory', function () {
    [$job, $reflection] = makeDockerBuildCacheDeploymentJob(localDockerBuildCacheDeploymentConfiguration());
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, new App\Models\Application(['uuid' => 'application-uuid']));

    $method = $reflection->getMethod('docker_build_cache_volume');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe("-v '/data/coolify/docker-build-cache/application-uuid:/cache'");
});

test('Dockerfile build command is unchanged when external cache is disabled', function () {
    $job = new TestableDockerBuildCacheDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $command = 'docker build --no-cache -t app:latest /artifacts/app';

    expect(invokeDockerBuildCacheCommand($job, $reflection, $command))->toBe($command);
});
