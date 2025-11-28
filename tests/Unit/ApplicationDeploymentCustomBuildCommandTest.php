<?php

/**
 * Test to verify that custom Docker Compose build commands properly inject flags.
 *
 * This test suite verifies that when using a custom build command, the system automatically
 * injects the -f (compose file path) and --env-file flags to ensure the correct compose file
 * is used and build-time environment variables are available during the build process.
 *
 * The fix ensures that:
 * - -f flag with compose file path is automatically injected after 'docker compose'
 * - --env-file /artifacts/build-time.env is automatically injected after 'docker compose'
 * - Users can still provide their own -f or --env-file flags to override the default behavior
 * - Both flags are injected in a single str_replace operation
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

// Tests for -f flag injection

it('injects -f flag with compose file path into custom build command', function () {
    $customCommand = 'docker compose build';
    $composeFilePath = '/artifacts/deployment-uuid/backend/docker-compose.yaml';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, $composeFilePath, '/artifacts/build-time.env');

    expect($customCommand)->toBe('docker compose -f /artifacts/deployment-uuid/backend/docker-compose.yaml --env-file /artifacts/build-time.env build');
    expect($customCommand)->toContain('-f /artifacts/deployment-uuid/backend/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('does not duplicate -f flag when already present', function () {
    $customCommand = 'docker compose -f ./custom/docker-compose.yaml build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env -f ./custom/docker-compose.yaml build');
    expect(substr_count($customCommand, ' -f '))->toBe(1);
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('does not duplicate --file flag when already present', function () {
    $customCommand = 'docker compose --file ./custom/docker-compose.yaml build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env --file ./custom/docker-compose.yaml build');
    expect(substr_count($customCommand, '--file '))->toBe(1);
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('injects both -f and --env-file flags in single operation', function () {
    $customCommand = 'docker compose build --no-cache';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/uuid/app/docker-compose.prod.yaml', '/artifacts/build-time.env');

    expect($customCommand)->toBe('docker compose -f /artifacts/uuid/app/docker-compose.prod.yaml --env-file /artifacts/build-time.env build --no-cache');
    expect($customCommand)->toContain('-f /artifacts/uuid/app/docker-compose.prod.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
    expect($customCommand)->toContain('build --no-cache');
});

it('respects user-provided -f and --env-file flags', function () {
    $customCommand = 'docker compose -f ./my-compose.yaml --env-file .env build';

    // Use the helper function - should not inject anything since both flags are already present
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    expect($customCommand)->toBe('docker compose -f ./my-compose.yaml --env-file .env build');
    expect(substr_count($customCommand, ' -f '))->toBe(1);
    expect(substr_count($customCommand, '--env-file'))->toBe(1);
});

// Tests for custom start command -f and --env-file injection

it('injects -f and --env-file flags into custom start command', function () {
    $customCommand = 'docker compose up -d';
    $serverWorkdir = '/var/lib/docker/volumes/coolify-data/_data/applications/app-uuid';
    $composeLocation = '/docker-compose.yaml';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, "{$serverWorkdir}{$composeLocation}", "{$serverWorkdir}/.env");

    expect($customCommand)->toBe('docker compose -f /var/lib/docker/volumes/coolify-data/_data/applications/app-uuid/docker-compose.yaml --env-file /var/lib/docker/volumes/coolify-data/_data/applications/app-uuid/.env up -d');
    expect($customCommand)->toContain('-f /var/lib/docker/volumes/coolify-data/_data/applications/app-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /var/lib/docker/volumes/coolify-data/_data/applications/app-uuid/.env');
});

it('does not duplicate -f flag in start command when already present', function () {
    $customCommand = 'docker compose -f ./custom-compose.yaml up -d';
    $serverWorkdir = '/var/lib/docker/volumes/coolify-data/_data/applications/app-uuid';
    $composeLocation = '/docker-compose.yaml';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, "{$serverWorkdir}{$composeLocation}", "{$serverWorkdir}/.env");

    expect($customCommand)->toBe('docker compose --env-file /var/lib/docker/volumes/coolify-data/_data/applications/app-uuid/.env -f ./custom-compose.yaml up -d');
    expect(substr_count($customCommand, ' -f '))->toBe(1);
    expect($customCommand)->toContain('--env-file');
});

it('does not duplicate --env-file flag in start command when already present', function () {
    $customCommand = 'docker compose --env-file ./my.env up -d';
    $serverWorkdir = '/var/lib/docker/volumes/coolify-data/_data/applications/app-uuid';
    $composeLocation = '/docker-compose.yaml';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, "{$serverWorkdir}{$composeLocation}", "{$serverWorkdir}/.env");

    expect($customCommand)->toBe('docker compose -f /var/lib/docker/volumes/coolify-data/_data/applications/app-uuid/docker-compose.yaml --env-file ./my.env up -d');
    expect(substr_count($customCommand, '--env-file'))->toBe(1);
    expect($customCommand)->toContain('-f');
});

it('respects both user-provided flags in start command', function () {
    $customCommand = 'docker compose -f ./my-compose.yaml --env-file ./.env up -d';
    $serverWorkdir = '/var/lib/docker/volumes/coolify-data/_data/applications/app-uuid';
    $composeLocation = '/docker-compose.yaml';

    // Use the helper function - should not inject anything since both flags are already present
    $customCommand = injectDockerComposeFlags($customCommand, "{$serverWorkdir}{$composeLocation}", "{$serverWorkdir}/.env");

    expect($customCommand)->toBe('docker compose -f ./my-compose.yaml --env-file ./.env up -d');
    expect(substr_count($customCommand, ' -f '))->toBe(1);
    expect(substr_count($customCommand, '--env-file'))->toBe(1);
});

it('injects both flags in start command with additional parameters', function () {
    $customCommand = 'docker compose up -d --remove-orphans';
    $serverWorkdir = '/workdir/app';
    $composeLocation = '/backend/docker-compose.prod.yaml';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, "{$serverWorkdir}{$composeLocation}", "{$serverWorkdir}/.env");

    expect($customCommand)->toBe('docker compose -f /workdir/app/backend/docker-compose.prod.yaml --env-file /workdir/app/.env up -d --remove-orphans');
    expect($customCommand)->toContain('-f /workdir/app/backend/docker-compose.prod.yaml');
    expect($customCommand)->toContain('--env-file /workdir/app/.env');
    expect($customCommand)->toContain('--remove-orphans');
});

// Security tests: Prevent bypass vectors for flag detection

it('detects -f flag with equals sign format (bypass vector)', function () {
    $customCommand = 'docker compose -f=./custom/docker-compose.yaml build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since -f= is already present
    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env -f=./custom/docker-compose.yaml build');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('detects --file flag with equals sign format (bypass vector)', function () {
    $customCommand = 'docker compose --file=./custom/docker-compose.yaml build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since --file= is already present
    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env --file=./custom/docker-compose.yaml build');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('detects --env-file flag with equals sign format (bypass vector)', function () {
    $customCommand = 'docker compose --env-file=./custom/.env build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject --env-file flag since --env-file= is already present
    expect($customCommand)->toBe('docker compose -f /artifacts/deployment-uuid/docker-compose.yaml --env-file=./custom/.env build');
    expect($customCommand)->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->not->toContain('--env-file /artifacts/build-time.env');
});

it('detects -f flag with tab character whitespace (bypass vector)', function () {
    $customCommand = "docker compose\t-f\t./custom/docker-compose.yaml build";

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since -f with tab is already present
    expect($customCommand)->toBe("docker compose --env-file /artifacts/build-time.env\t-f\t./custom/docker-compose.yaml build");
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('detects --env-file flag with tab character whitespace (bypass vector)', function () {
    $customCommand = "docker compose\t--env-file\t./custom/.env build";

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject --env-file flag since --env-file with tab is already present
    expect($customCommand)->toBe("docker compose -f /artifacts/deployment-uuid/docker-compose.yaml\t--env-file\t./custom/.env build");
    expect($customCommand)->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->not->toContain('--env-file /artifacts/build-time.env');
});

it('detects -f flag with multiple spaces (bypass vector)', function () {
    $customCommand = 'docker compose  -f  ./custom/docker-compose.yaml build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since -f with multiple spaces is already present
    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env  -f  ./custom/docker-compose.yaml build');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('detects --file flag with multiple spaces (bypass vector)', function () {
    $customCommand = 'docker compose  --file  ./custom/docker-compose.yaml build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since --file with multiple spaces is already present
    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env  --file  ./custom/docker-compose.yaml build');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('detects -f flag at start of command (edge case)', function () {
    $customCommand = '-f ./custom/docker-compose.yaml docker compose build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since -f is at start of command
    expect($customCommand)->toBe('-f ./custom/docker-compose.yaml docker compose --env-file /artifacts/build-time.env build');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('detects --env-file flag at start of command (edge case)', function () {
    $customCommand = '--env-file=./custom/.env docker compose build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject --env-file flag since --env-file is at start of command
    expect($customCommand)->toBe('--env-file=./custom/.env docker compose -f /artifacts/deployment-uuid/docker-compose.yaml build');
    expect($customCommand)->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->not->toContain('--env-file /artifacts/build-time.env');
});

it('handles mixed whitespace correctly (comprehensive test)', function () {
    $customCommand = "docker compose\t-f=./custom/docker-compose.yaml  --env-file\t./custom/.env build";

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject any flags since both are already present with various whitespace
    expect($customCommand)->toBe("docker compose\t-f=./custom/docker-compose.yaml  --env-file\t./custom/.env build");
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->not->toContain('--env-file /artifacts/build-time.env');
});

// Tests for concatenated -f flag format (no space, no equals)

it('detects -f flag in concatenated format -fvalue (bypass vector)', function () {
    $customCommand = 'docker compose -f./custom/docker-compose.yaml build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since -f is concatenated with value
    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env -f./custom/docker-compose.yaml build');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

it('detects -f flag concatenated with path containing slash', function () {
    $customCommand = 'docker compose -f/path/to/compose.yml up -d';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since -f is concatenated
    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env -f/path/to/compose.yml up -d');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('-f/path/to/compose.yml');
});

it('detects -f flag concatenated at start of command', function () {
    $customCommand = '-f./compose.yaml docker compose build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag since -f is already present (even at start)
    expect($customCommand)->toBe('-f./compose.yaml docker compose --env-file /artifacts/build-time.env build');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
});

it('detects concatenated -f flag with relative path', function () {
    $customCommand = 'docker compose -f../docker-compose.prod.yaml build --no-cache';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Should NOT inject -f flag
    expect($customCommand)->toBe('docker compose --env-file /artifacts/build-time.env -f../docker-compose.prod.yaml build --no-cache');
    expect($customCommand)->not->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('-f../docker-compose.prod.yaml');
});

it('correctly injects when no -f flag is present (sanity check after concatenated fix)', function () {
    $customCommand = 'docker compose build --no-cache';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/deployment-uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // SHOULD inject both flags
    expect($customCommand)->toBe('docker compose -f /artifacts/deployment-uuid/docker-compose.yaml --env-file /artifacts/build-time.env build --no-cache');
    expect($customCommand)->toContain('-f /artifacts/deployment-uuid/docker-compose.yaml');
    expect($customCommand)->toContain('--env-file /artifacts/build-time.env');
});

// Edge case tests: First occurrence only replacement

it('only replaces first docker compose occurrence in chained commands', function () {
    $customCommand = 'docker compose pull && docker compose build';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Only the FIRST 'docker compose' should get the flags
    expect($customCommand)->toBe('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env pull && docker compose build');
    expect($customCommand)->toContain('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env pull');
    expect($customCommand)->toContain(' && docker compose build');
    // Verify the second occurrence is NOT modified
    expect(substr_count($customCommand, '-f /artifacts/uuid/docker-compose.yaml'))->toBe(1);
    expect(substr_count($customCommand, '--env-file /artifacts/build-time.env'))->toBe(1);
});

it('does not modify docker compose string in echo statements', function () {
    $customCommand = 'docker compose build && echo "docker compose finished successfully"';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Only the FIRST 'docker compose' (the command) should get flags, NOT the echo message
    expect($customCommand)->toBe('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env build && echo "docker compose finished successfully"');
    expect($customCommand)->toContain('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env build');
    expect($customCommand)->toContain('echo "docker compose finished successfully"');
    // Verify echo message is NOT modified
    expect(substr_count($customCommand, 'docker compose', 0))->toBe(2); // Two total occurrences
    expect(substr_count($customCommand, '-f /artifacts/uuid/docker-compose.yaml'))->toBe(1); // Only first has flags
});

it('does not modify docker compose string in bash comments', function () {
    $customCommand = 'docker compose build # This runs docker compose to build the image';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // Only the FIRST 'docker compose' (the command) should get flags, NOT the comment
    expect($customCommand)->toBe('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env build # This runs docker compose to build the image');
    expect($customCommand)->toContain('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env build');
    expect($customCommand)->toContain('# This runs docker compose to build the image');
    // Verify comment is NOT modified
    expect(substr_count($customCommand, 'docker compose', 0))->toBe(2); // Two total occurrences
    expect(substr_count($customCommand, '-f /artifacts/uuid/docker-compose.yaml'))->toBe(1); // Only first has flags
});

// False positive prevention tests: Flags like -foo, -from, -feature should NOT be detected as -f

it('injects -f flag when command contains -foo flag (not -f)', function () {
    $customCommand = 'docker compose build --foo bar';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // SHOULD inject -f flag because -foo is NOT the -f flag
    expect($customCommand)->toBe('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env build --foo bar');
    expect($customCommand)->toContain('-f /artifacts/uuid/docker-compose.yaml');
});

it('injects -f flag when command contains --from flag (not -f)', function () {
    $customCommand = 'docker compose build --from cache-image';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // SHOULD inject -f flag because --from is NOT the -f flag
    expect($customCommand)->toBe('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env build --from cache-image');
    expect($customCommand)->toContain('-f /artifacts/uuid/docker-compose.yaml');
});

it('injects -f flag when command contains -feature flag (not -f)', function () {
    $customCommand = 'docker compose build -feature test';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // SHOULD inject -f flag because -feature is NOT the -f flag
    expect($customCommand)->toBe('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env build -feature test');
    expect($customCommand)->toContain('-f /artifacts/uuid/docker-compose.yaml');
});

it('injects -f flag when command contains -fast flag (not -f)', function () {
    $customCommand = 'docker compose build -fast';

    // Use the helper function
    $customCommand = injectDockerComposeFlags($customCommand, '/artifacts/uuid/docker-compose.yaml', '/artifacts/build-time.env');

    // SHOULD inject -f flag because -fast is NOT the -f flag
    expect($customCommand)->toBe('docker compose -f /artifacts/uuid/docker-compose.yaml --env-file /artifacts/build-time.env build -fast');
    expect($customCommand)->toContain('-f /artifacts/uuid/docker-compose.yaml');
});

// Path normalization tests for preview methods

it('normalizes path when baseDirectory is root slash', function () {
    $baseDirectory = '/';
    $composeLocation = '/docker-compose.yaml';

    // Normalize baseDirectory to prevent double slashes
    $normalizedBase = $baseDirectory === '/' ? '' : rtrim($baseDirectory, '/');
    $path = ".{$normalizedBase}{$composeLocation}";

    expect($path)->toBe('./docker-compose.yaml');
    expect($path)->not->toContain('//');
});

it('normalizes path when baseDirectory has trailing slash', function () {
    $baseDirectory = '/backend/';
    $composeLocation = '/docker-compose.yaml';

    // Normalize baseDirectory to prevent double slashes
    $normalizedBase = $baseDirectory === '/' ? '' : rtrim($baseDirectory, '/');
    $path = ".{$normalizedBase}{$composeLocation}";

    expect($path)->toBe('./backend/docker-compose.yaml');
    expect($path)->not->toContain('//');
});

it('handles empty baseDirectory correctly', function () {
    $baseDirectory = '';
    $composeLocation = '/docker-compose.yaml';

    // Normalize baseDirectory to prevent double slashes
    $normalizedBase = $baseDirectory === '/' ? '' : rtrim($baseDirectory, '/');
    $path = ".{$normalizedBase}{$composeLocation}";

    expect($path)->toBe('./docker-compose.yaml');
    expect($path)->not->toContain('//');
});

it('handles normal baseDirectory without trailing slash', function () {
    $baseDirectory = '/backend';
    $composeLocation = '/docker-compose.yaml';

    // Normalize baseDirectory to prevent double slashes
    $normalizedBase = $baseDirectory === '/' ? '' : rtrim($baseDirectory, '/');
    $path = ".{$normalizedBase}{$composeLocation}";

    expect($path)->toBe('./backend/docker-compose.yaml');
    expect($path)->not->toContain('//');
});

it('handles nested baseDirectory with trailing slash', function () {
    $baseDirectory = '/app/backend/';
    $composeLocation = '/docker-compose.prod.yaml';

    // Normalize baseDirectory to prevent double slashes
    $normalizedBase = $baseDirectory === '/' ? '' : rtrim($baseDirectory, '/');
    $path = ".{$normalizedBase}{$composeLocation}";

    expect($path)->toBe('./app/backend/docker-compose.prod.yaml');
    expect($path)->not->toContain('//');
});

it('produces correct preview path with normalized baseDirectory', function () {
    $testCases = [
        ['baseDir' => '/', 'compose' => '/docker-compose.yaml', 'expected' => './docker-compose.yaml'],
        ['baseDir' => '', 'compose' => '/docker-compose.yaml', 'expected' => './docker-compose.yaml'],
        ['baseDir' => '/backend', 'compose' => '/docker-compose.yaml', 'expected' => './backend/docker-compose.yaml'],
        ['baseDir' => '/backend/', 'compose' => '/docker-compose.yaml', 'expected' => './backend/docker-compose.yaml'],
        ['baseDir' => '/app/src/', 'compose' => '/docker-compose.prod.yaml', 'expected' => './app/src/docker-compose.prod.yaml'],
    ];

    foreach ($testCases as $case) {
        $normalizedBase = $case['baseDir'] === '/' ? '' : rtrim($case['baseDir'], '/');
        $path = ".{$normalizedBase}{$case['compose']}";

        expect($path)->toBe($case['expected'], "Failed for baseDir: {$case['baseDir']}");
        expect($path)->not->toContain('//', "Double slash found for baseDir: {$case['baseDir']}");
    }
});
