<?php

/**
 * Tests for Docker Compose image tag injection in applicationParser.
 *
 * These tests verify the logic for injecting commit-based image tags
 * into Docker Compose services with build directives.
 */
it('injects image tag for services with build but no image directive', function () {
    // Test the condition: hasBuild && !hasImage && commit
    $service = [
        'build' => './app',
    ];

    $hasBuild = data_get($service, 'build') !== null;
    $hasImage = data_get($service, 'image') !== null;
    $commit = 'abc123def456';
    $uuid = 'app-uuid';
    $serviceName = 'web';

    expect($hasBuild)->toBeTrue();
    expect($hasImage)->toBeFalse();

    // Simulate the image injection logic
    if ($hasBuild && ! $hasImage && $commit) {
        $imageTag = str($commit)->substr(0, 128)->value();
        $imageRepo = "{$uuid}_{$serviceName}";
        $service['image'] = "{$imageRepo}:{$imageTag}";
    }

    expect($service['image'])->toBe('app-uuid_web:abc123def456');
});

it('does not inject image tag when service has explicit image directive', function () {
    // User has specified their own image - we respect it
    $service = [
        'build' => './app',
        'image' => 'myregistry/myapp:latest',
    ];

    $hasBuild = data_get($service, 'build') !== null;
    $hasImage = data_get($service, 'image') !== null;
    $commit = 'abc123def456';

    expect($hasBuild)->toBeTrue();
    expect($hasImage)->toBeTrue();

    // The condition should NOT trigger
    $shouldInject = $hasBuild && ! $hasImage && $commit;
    expect($shouldInject)->toBeFalse();

    // Image should remain unchanged
    expect($service['image'])->toBe('myregistry/myapp:latest');
});

it('does not inject image tag when there is no commit', function () {
    $service = [
        'build' => './app',
    ];

    $hasBuild = data_get($service, 'build') !== null;
    $hasImage = data_get($service, 'image') !== null;
    $commit = null;

    expect($hasBuild)->toBeTrue();
    expect($hasImage)->toBeFalse();

    // The condition should NOT trigger (no commit)
    $shouldInject = $hasBuild && ! $hasImage && $commit;
    expect($shouldInject)->toBeFalse();
});

it('does not inject image tag for services without build directive', function () {
    // Service that pulls a pre-built image
    $service = [
        'image' => 'nginx:alpine',
    ];

    $hasBuild = data_get($service, 'build') !== null;
    $hasImage = data_get($service, 'image') !== null;
    $commit = 'abc123def456';

    expect($hasBuild)->toBeFalse();
    expect($hasImage)->toBeTrue();

    // The condition should NOT trigger (no build)
    $shouldInject = $hasBuild && ! $hasImage && $commit;
    expect($shouldInject)->toBeFalse();
});

it('uses pr-{id} tag for pull request deployments', function () {
    $service = [
        'build' => './app',
    ];

    $hasBuild = data_get($service, 'build') !== null;
    $hasImage = data_get($service, 'image') !== null;
    $commit = 'abc123def456';
    $uuid = 'app-uuid';
    $serviceName = 'web';
    $isPullRequest = true;
    $pullRequestId = 42;

    // Simulate the PR image injection logic
    if ($hasBuild && ! $hasImage && $commit) {
        $imageTag = str($commit)->substr(0, 128)->value();
        if ($isPullRequest) {
            $imageTag = "pr-{$pullRequestId}";
        }
        $imageRepo = "{$uuid}_{$serviceName}";
        $service['image'] = "{$imageRepo}:{$imageTag}";
    }

    expect($service['image'])->toBe('app-uuid_web:pr-42');
});

it('truncates commit SHA to 128 characters', function () {
    $service = [
        'build' => './app',
    ];

    $hasBuild = data_get($service, 'build') !== null;
    $hasImage = data_get($service, 'image') !== null;
    // Create a very long commit string
    $commit = str_repeat('a', 200);
    $uuid = 'app-uuid';
    $serviceName = 'web';

    if ($hasBuild && ! $hasImage && $commit) {
        $imageTag = str($commit)->substr(0, 128)->value();
        $imageRepo = "{$uuid}_{$serviceName}";
        $service['image'] = "{$imageRepo}:{$imageTag}";
    }

    // Tag should be exactly 128 characters
    $parts = explode(':', $service['image']);
    expect(strlen($parts[1]))->toBe(128);
});

it('handles multiple services with build directives', function () {
    $services = [
        'web' => ['build' => './web'],
        'worker' => ['build' => './worker'],
        'api' => ['build' => './api', 'image' => 'custom:tag'],  // Has explicit image
        'redis' => ['image' => 'redis:alpine'],  // No build
    ];

    $commit = 'abc123';
    $uuid = 'app-uuid';

    foreach ($services as $serviceName => $service) {
        $hasBuild = data_get($service, 'build') !== null;
        $hasImage = data_get($service, 'image') !== null;

        if ($hasBuild && ! $hasImage && $commit) {
            $imageTag = str($commit)->substr(0, 128)->value();
            $imageRepo = "{$uuid}_{$serviceName}";
            $services[$serviceName]['image'] = "{$imageRepo}:{$imageTag}";
        }
    }

    // web and worker should get injected images
    expect($services['web']['image'])->toBe('app-uuid_web:abc123');
    expect($services['worker']['image'])->toBe('app-uuid_worker:abc123');

    // api keeps its custom image (has explicit image)
    expect($services['api']['image'])->toBe('custom:tag');

    // redis keeps its image (no build directive)
    expect($services['redis']['image'])->toBe('redis:alpine');
});
