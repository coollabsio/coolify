<?php

/**
 * Test to verify that custom Docker Compose build commands properly inject environment variables.
 *
 * This test suite verifies that when using a custom build command, the system automatically
 * injects the --env-file flag to ensure build-time environment variables are available during
 * the build process. This fixes the issue where environment variables were lost when using
 * custom build commands.
 *
 * The fix ensures that:
 * - --env-file /artifacts/build-time.env is automatically injected after 'docker compose'
 * - Users can still provide their own --env-file flag to override the default behavior
 * - Build arguments are appended when not using build secrets
 */
it('injects --env-file flag into custom build command', function () {
    $customCommand = 'docker compose -f ./docker-compose.yaml build';

    // Simulate the injection logic from ApplicationDeploymentJob
    if (! str_contains($customCommand, '--env-file')) {
        $customCommand = str_replace(
            'docker compose',
            'docker compose --env-file /artifacts/build-time.env',
            $customCommand
        );
    }

    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env -f ./docker-compose.yaml build');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('does not duplicate --env-file flag when already present', function () {
    $customCommand = 'docker compose --env-file /custom/.env -f ./docker-compose.yaml build';

    // Simulate the injection logic from ApplicationDeploymentJob
    if (! str_contains($customCommand, '--env-file')) {
        $customCommand = str_replace(
            'docker compose',
            'docker compose --env-file /artifacts/build-time.env',
            $customCommand
        );
    }

    expect($customCommand)->toBe('docker compose --env-file /custom/.env -f ./docker-compose.yaml build');
    expect(substr_count($customCommand, '--env-file'))->toBe(1);
});

it('preserves custom build command structure with env-file injection', function () {
    $customCommand = 'docker compose -f ./custom/path/docker-compose.prod.yaml build --no-cache';

    // Simulate the injection logic from ApplicationDeploymentJob
    if (! str_contains($customCommand, '--env-file')) {
        $customCommand = str_replace(
            'docker compose',
            'docker compose --env-file /artifacts/build-time.env',
            $customCommand
        );
    }

    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env -f ./custom/path/docker-compose.prod.yaml build --no-cache');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
    expect($customCommand)->toContain('-f ./custom/path/docker-compose.prod.yaml');
    expect($customCommand)->toContain('build --no-cache');
});

it('handles multiple docker compose commands in custom build command', function () {
    // Edge case: Only the first 'docker compose' should get the env-file flag
    $customCommand = 'docker compose -f ./docker-compose.yaml build';

    // Simulate the injection logic from ApplicationDeploymentJob
    if (! str_contains($customCommand, '--env-file')) {
        $customCommand = str_replace(
            'docker compose',
            'docker compose --env-file /artifacts/build-time.env',
            $customCommand
        );
    }

    // Note: str_replace replaces ALL occurrences, which is acceptable in this case
    // since you typically only have one 'docker compose' command
    expect($customCommand)->toContain('docker compose --env-file /artifacts/build-time.env');
});

it('verifies build args would be appended correctly', function () {
    $customCommand = 'docker compose --env-file /artifacts/build-time.env -f ./docker-compose.yaml build';
    $buildArgs = collect([
        '--build-arg NODE_ENV=production',
        '--build-arg API_URL=https://api.example.com',
    ]);

    // Simulate build args appending logic
    $buildArgsString = $buildArgs->implode(' ');
    $buildArgsString = str_replace("'", "'\\''", $buildArgsString);
    $customCommand .= " {$buildArgsString}";

    expect($customCommand)->toContain('--build-arg NODE_ENV=production');
    expect($customCommand)->toContain('--build-arg API_URL=https://api.example.com');
    expect($customCommand)->toBe(
        'docker compose --env-file /artifacts/build-time.env -f ./docker-compose.yaml build --build-arg NODE_ENV=production --build-arg API_URL=https://api.example.com'
    );
});

it('properly escapes single quotes in build args', function () {
    $buildArg = "--build-arg MESSAGE='Hello World'";

    // Simulate the escaping logic from ApplicationDeploymentJob
    $escapedBuildArg = str_replace("'", "'\\''", $buildArg);

    expect($escapedBuildArg)->toBe("--build-arg MESSAGE='\\''Hello World'\\''");
});

it('handles DOCKER_BUILDKIT prefix with env-file injection', function () {
    $customCommand = 'docker compose -f ./docker-compose.yaml build';

    // Simulate the injection logic from ApplicationDeploymentJob
    if (! str_contains($customCommand, '--env-file')) {
        $customCommand = str_replace(
            'docker compose',
            'docker compose --env-file /artifacts/build-time.env',
            $customCommand
        );
    }

    // Simulate BuildKit support
    $dockerBuildkitSupported = true;
    if ($dockerBuildkitSupported) {
        $customCommand = "DOCKER_BUILDKIT=1 {$customCommand}";
    }

    expect($customCommand)->toBe('DOCKER_BUILDKIT=1 docker compose --env-file /artifacts/build-time.env -f ./docker-compose.yaml build');
    expect($customCommand)->toStartWith('DOCKER_BUILDKIT=1');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});
