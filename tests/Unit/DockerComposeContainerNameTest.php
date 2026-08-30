<?php

use Illuminate\Support\Collection;

it('does not add a container name when the user did not configure one', function () {
    $service = applyDockerComposeContainerName(collect(['image' => 'nginx']), null);

    expect($service)->toBeInstanceOf(Collection::class)
        ->and($service)->not->toHaveKey('container_name');
});

it('preserves a container name explicitly configured by the user', function () {
    $service = applyDockerComposeContainerName(collect(['image' => 'nginx']), 'custom-api');

    expect($service)->toHaveKey('container_name', 'custom-api');
});

it('namespaces an explicitly configured container name for previews', function () {
    $containerName = generateDockerComposeContainerName(
        projectName: 'application-uuid-pr-42',
        serviceName: 'api',
        configuredContainerName: 'custom-api',
        pullRequestId: 42,
    );

    expect($containerName)->toBe('custom-api-pr-42');
});

it('uses unique compose projects for production and previews', function () {
    expect(generateDockerComposeProjectName('application-uuid'))
        ->toBe('application-uuid')
        ->and(generateDockerComposeProjectName('application-uuid', 42))
        ->toBe('application-uuid-pr-42')
        ->not->toBe(generateDockerComposeProjectName('application-uuid'));
});

it('provides the compose generated name as informational metadata', function () {
    expect(generateDockerComposeContainerName('application-uuid', 'api'))
        ->toBe('application-uuid-api-1')
        ->and(generateDockerComposeContainerName('application-uuid-pr-42', 'api'))
        ->toBe('application-uuid-pr-42-api-1')
        ->and(generateDockerComposeContainerName('application-uuid', 'api', 'custom-api'))
        ->toBe('custom-api');
});

it('reads compose identity from docker container labels', function () {
    $container = [
        'Labels' => 'coolify.applicationId=123,com.docker.compose.project=application-uuid,com.docker.compose.service=api',
    ];

    expect(dockerContainerLabel($container, 'com.docker.compose.project'))->toBe('application-uuid')
        ->and(dockerContainerLabel($container, 'com.docker.compose.service'))->toBe('api');
});

it('reads dotted compose labels from a flat label array', function () {
    $container = [
        'Labels' => [
            'com.docker.compose.service' => 'api',
        ],
    ];

    expect(dockerContainerLabel($container, 'com.docker.compose.service'))->toBe('api');
});

it('keeps the container name environment variable informational', function () {
    $source = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');
    $start = strpos($source, 'function applicationParser(');
    $end = strpos($source, 'function serviceParser(', $start);
    $applicationParser = substr($source, $start, $end - $start);

    expect($applicationParser)
        ->toContain("put('COOLIFY_CONTAINER_NAME'")
        ->toContain('applyDockerComposeContainerName');
});
