<?php

use App\Jobs\ApplicationDeploymentJob;

function resolveComposeDockerfilePath(string|array $build): string
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'resolveComposeDockerfilePath');

    return $method->invoke($job, $build);
}

test('compose build paths resolve for short and long syntax', function (string|array $build, string $expected) {
    expect(resolveComposeDockerfilePath($build))->toBe($expected);
})->with([
    'short syntax' => ['services/api', 'services/api/Dockerfile'],
    'current directory short syntax' => ['.', 'Dockerfile'],
    'long syntax defaults' => [['context' => 'services/api'], 'services/api/Dockerfile'],
    'nested Dockerfile' => [['context' => './services/api', 'dockerfile' => 'docker/prod.Dockerfile'], 'services/api/docker/prod.Dockerfile'],
    'standard Dockerfile variants' => [['context' => '.', 'dockerfile' => 'Dockerfile.prod'], 'Dockerfile.prod'],
    'traversal that stays in repository' => [['context' => 'services/api', 'dockerfile' => '../Dockerfile'], 'services/Dockerfile'],
]);

test('compose build paths reject repository escape and shell command injection', function (string|array $build) {
    expect(fn () => resolveComposeDockerfilePath($build))
        ->toThrow(RuntimeException::class);
})->with([
    'short syntax semicolon' => ['.; touch /tmp/short-context-pwned'],
    'short syntax command substitution' => ['$(touch /tmp/short-context-pwned)'],
    'context semicolon' => [['context' => '.; touch /tmp/context-pwned', 'dockerfile' => 'Dockerfile']],
    'dockerfile semicolon' => [['context' => '.', 'dockerfile' => 'Dockerfile; touch /tmp/dockerfile-pwned']],
    'context command substitution' => [['context' => '$(touch /tmp/context-pwned)', 'dockerfile' => 'Dockerfile']],
    'dockerfile command substitution' => [['context' => '.', 'dockerfile' => '$(touch /tmp/dockerfile-pwned)']],
    'context newline' => [['context' => "services/api\ntouch /tmp/context-pwned", 'dockerfile' => 'Dockerfile']],
    'dockerfile newline' => [['context' => '.', 'dockerfile' => "Dockerfile\ntouch /tmp/dockerfile-pwned"]],
    'context traversal' => [['context' => '../outside', 'dockerfile' => 'Dockerfile']],
    'nested traversal' => [['context' => 'services/api', 'dockerfile' => '../../../outside.Dockerfile']],
    'dockerfile traversal' => [['context' => '.', 'dockerfile' => '../outside.Dockerfile']],
    'context absolute path' => [['context' => '/tmp', 'dockerfile' => 'Dockerfile']],
    'dockerfile absolute path' => [['context' => '.', 'dockerfile' => '/tmp/Dockerfile']],
]);
