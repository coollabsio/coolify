<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;

function resolveComposeDockerfilePath(mixed $build): ?string
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'resolveComposeDockerfilePath');

    return $method->invoke($job, $build);
}

test('compose build paths resolve for short and long syntax', function (string|array $build, string $expected) {
    // The path is not trusted here: the ARG injection step quotes it and confines the resolved file.
    expect(resolveComposeDockerfilePath($build))->toBe($expected);
})->with([
    'short syntax' => ['services/api', 'services/api/Dockerfile'],
    'current directory short syntax' => ['.', './Dockerfile'],
    'long syntax defaults' => [['context' => 'services/api'], 'services/api/Dockerfile'],
    'nested Dockerfile' => [['context' => './services/api', 'dockerfile' => 'docker/prod.Dockerfile'], './services/api/docker/prod.Dockerfile'],
    'standard Dockerfile variants' => [['context' => '.', 'dockerfile' => 'Dockerfile.prod'], './Dockerfile.prod'],
    'monorepo context above the base directory' => [['context' => '..'], '../Dockerfile'],
    'path with a space' => ['my app', 'my app/Dockerfile'],
    'absolute Dockerfile' => [['context' => '.', 'dockerfile' => '/srv/app/Dockerfile'], '/srv/app/Dockerfile'],
]);

test('compose build contexts that Coolify cannot inspect locally are skipped', function (mixed $build) {
    expect(resolveComposeDockerfilePath($build))->toBeNull();
})->with([
    'git URL' => ['https://github.com/coollabsio/coolify.git#main:docker'],
    'git SSH URL' => [['context' => 'git@github.com:coollabsio/coolify.git']],
    'context variable' => ['${APP_DIR:-.}'],
    'Dockerfile variable' => [['context' => '.', 'dockerfile' => '${DOCKERFILE}']],
    'command substitution' => [['context' => '$(touch /tmp/context-pwned)']],
    'inline Dockerfile' => [['context' => '.', 'dockerfile_inline' => "FROM alpine\n"]],
    'empty context' => [''],
    'non-string context' => [['context' => ['nested']]],
    'invalid build definition' => [42],
]);

function composeBuildContextOutsideRepository(mixed $build, string $baseDirectory): ?string
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'composeBuildContextOutsideRepository');

    return $method->invoke($job, $build, $baseDirectory);
}

test('compose build contexts that leave the cloned repository are found', function (mixed $build, string $baseDirectory) {
    // Docker Compose resolves the context from the base directory, so these contexts point outside the clone.
    expect(composeBuildContextOutsideRepository($build, $baseDirectory))->not->toBeNull();
})->with([
    'parent of the repository root (#5182)' => [['context' => '..', 'dockerfile' => 'docker/Dockerfile'], '/'],
    'short syntax' => ['../', '/'],
    'two levels up from a subdirectory Compose file' => [['context' => '../../'], '/'],
    'above a base directory' => [['context' => '../..'], '/apps'],
    'down and then out' => [['context' => './docker/../../'], '/'],
]);

test('compose build contexts inside the cloned repository are kept', function (mixed $build, string $baseDirectory) {
    expect(composeBuildContextOutsideRepository($build, $baseDirectory))->toBeNull();
})->with([
    'repository root' => [['context' => '.'], '/'],
    'subdirectory' => ['services/api', '/'],
    'monorepo context above the base directory' => [['context' => '..'], '/apps/web'],
    'base directory without a leading slash' => [['context' => '..'], 'apps'],
    'absolute context' => [['context' => '/srv/app'], '/'],
    'git URL' => ['https://github.com/coollabsio/coolify.git#main:docker', '/'],
    'context variable' => [['context' => '${APP_DIR:-..}'], '/'],
    'no context' => [['dockerfile' => 'Dockerfile'], '/'],
    'invalid build definition' => [42, '/'],
]);

test('a deployment stops with the base directory fix when a build context leaves the repository', function () {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $reflection->getProperty('application')->setValue($job, new Application(['base_directory' => '/']));
    $reflection->getProperty('docker_compose_location')->setValue($job, '/docker/docker-compose.yml');
    $composeFile = collect(['services' => ['fastapi' => ['build' => ['context' => '..', 'dockerfile' => 'docker/Dockerfile']]]]);

    expect(fn () => (new ReflectionMethod($job, 'ensure_compose_build_contexts_inside_repository'))->invoke($job, $composeFile))
        ->toThrow(DeploymentException::class, 'set Base Directory to /docker and Docker Compose Location to /docker-compose.yml');
});
