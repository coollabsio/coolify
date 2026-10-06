<?php

use App\Jobs\ApplicationDeploymentJob;

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
