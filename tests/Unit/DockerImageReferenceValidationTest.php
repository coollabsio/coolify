<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Rules\DockerImageFormat;
use App\Support\ValidationPatterns;

it('accepts valid docker registry image names', function (string $imageName) {
    expect(ValidationPatterns::isValidDockerImageName($imageName))->toBeTrue();
})->with([
    'single component' => 'nginx',
    'namespace image' => 'library/nginx',
    'ghcr image' => 'ghcr.io/coollabsio/coolify',
    'repository component with repeated hyphens' => 'ghcr.io/acme/my--service',
    'registry with port' => 'registry.example.com:5000/team/app',
    'digest marker used by existing dockerimage records' => 'nginx@sha256',
]);

it('rejects docker registry image names with shell metacharacters', function (string $imageName) {
    expect(ValidationPatterns::isValidDockerImageName($imageName))->toBeFalse();
})->with([
    'command substitution' => 'coolify/poc$(touch /tmp/pwned)',
    'semicolon' => 'coolify/poc;id',
    'backticks' => 'coolify/poc`id`',
    'pipe' => 'coolify/poc|id',
    'logical and' => 'coolify/poc&&id',
    'newline' => "coolify/poc\nid",
    'space' => 'coolify/poc image',
    'tag in image-name-only field' => 'coolify/poc:latest',
]);

it('accepts valid docker registry image tags', function (string $tag) {
    expect(ValidationPatterns::isValidDockerImageTag($tag))->toBeTrue();
})->with([
    'latest' => 'latest',
    'version' => 'v1.2.3',
    'uppercase and underscore' => 'PR_123',
    'sha256 hash' => '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
    'legacy sha256 prefixed hash' => 'sha256-1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
]);

it('rejects docker registry image tags with shell metacharacters', function (string $tag) {
    expect(ValidationPatterns::isValidDockerImageTag($tag))->toBeFalse();
})->with([
    'command substitution' => 'latest$(touch /tmp/pwned)',
    'semicolon' => 'latest;id',
    'backticks' => 'latest`id`',
    'pipe' => 'latest|id',
    'logical and' => 'latest&&id',
    'newline' => "latest\nid",
]);

it('accepts supported full docker image reference formats', function (string $imageReference) {
    $failures = [];

    (new DockerImageFormat)->validate('image', $imageReference, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures)->toBeEmpty();
})->with([
    'image with tag' => 'nginx:latest',
    'registry image with tag' => 'ghcr.io/user/app:v1.2.3',
    'image with sha256 digest' => 'nginx@sha256:1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
    'registry image with sha256 digest' => 'ghcr.io/user/app@sha256:1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
    'registry port image with tag' => 'localhost:5000/app:latest',
]);

it('rejects unsupported full docker image reference formats', function (string $imageReference) {
    $failures = [];

    (new DockerImageFormat)->validate('image', $imageReference, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures)->not->toBeEmpty();
})->with([
    'colon sha256 marker' => 'nginx:sha256:1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
    'command substitution' => 'nginx:latest$(touch /tmp/pwned)',
    'newline' => "nginx:latest\nid",
]);

it('stops deployments when a stored docker registry image value is unsafe', function () {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    $application = new Application([
        'docker_registry_image_name' => 'coolify/poc$(touch /tmp/pwned)',
        'docker_registry_image_tag' => 'latest',
    ]);
    $deploymentQueue = new ApplicationDeploymentQueue([
        'docker_registry_image_tag' => null,
    ]);

    $jobReflection = new ReflectionClass($job);
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $deploymentQueue,
        'dockerImagePreviewTag' => null,
    ] as $property => $value) {
        $reflectionProperty = $jobReflection->getProperty($property);
        $reflectionProperty->setValue($job, $value);
    }

    $method = $jobReflection->getMethod('validateDockerRegistryImageConfiguration');

    expect(fn () => $method->invoke($job))->toThrow(DeploymentException::class);
});
