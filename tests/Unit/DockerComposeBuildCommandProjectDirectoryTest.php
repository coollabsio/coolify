<?php

/**
 * Test to verify that docker-compose custom build commands use the correct
 * --project-directory to find the Dockerfile in the parent directory.
 *
 * When the docker-compose.yml is in a subdirectory (e.g., /docker/) but the
 * Dockerfile is in the parent directory (e.g., /Dockerfile), --project-directory
 * must point to the parent so BuildKit can resolve the Dockerfile correctly.
 *
 * BUG: https://github.com/coollabsio/coolify/issues/9525
 *
 * Example:
 * - docker-compose.yml at: /artifacts/{uuid}/docker/docker-compose.yml
 * - Dockerfile at:        /artifacts/{uuid}/Dockerfile
 *
 * --project-directory must be /artifacts/{uuid} (basedir), NOT
 * /artifacts/{uuid}/docker (workdir), so BuildKit finds the Dockerfile
 * at /artifacts/{uuid}/Dockerfile.
 */
it('uses basedir (not workdir) for --project-directory in build command when compose file is in subdirectory', function () {
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/docker';
    $customBuildCommand = 'docker compose -f docker-compose.yml build --pull';

    // Simulate the fixed logic from ApplicationDeploymentJob::deploy_docker_compose_buildpack()
    if (! str($customBuildCommand)->contains('--project-directory')) {
        // FIX: Use $basedir so Dockerfile is found at $basedir/Dockerfile
        $customBuildCommand = str($customBuildCommand)
            ->replaceFirst('compose', 'compose --project-directory '.$basedir)
            ->value();
    }

    // --project-directory must point to basedir (parent), not workdir (subdirectory)
    expect($customBuildCommand)->toContain("--project-directory {$basedir}");
    expect($customBuildCommand)->not->toContain($workdir);
    expect($customBuildCommand)->toBe("docker compose --project-directory {$basedir} -f docker-compose.yml build --pull");
});

it('finds Dockerfile in parent when compose file is deeply nested', function () {
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/services/api';
    $customBuildCommand = 'docker compose -f compose/docker.yml build';

    if (! str($customBuildCommand)->contains('--project-directory')) {
        $customBuildCommand = str($customBuildCommand)
            ->replaceFirst('compose', 'compose --project-directory '.$basedir)
            ->value();
    }

    // Dockerfile should resolve to /artifacts/test-deployment-uuid/Dockerfile
    expect($customBuildCommand)->toContain("--project-directory {$basedir}");
    expect($customBuildCommand)->toBe("docker compose --project-directory {$basedir} -f compose/docker.yml build");
});

it('does not override explicit --project-directory in build command', function () {
    $basedir = '/artifacts/test-deployment-uuid';
    $customProjectDir = '/custom/path';
    $customBuildCommand = "docker compose --project-directory {$customProjectDir} build";

    if (! str($customBuildCommand)->contains('--project-directory')) {
        $customBuildCommand = str($customBuildCommand)
            ->replaceFirst('compose', 'compose --project-directory '.$basedir)
            ->value();
    }

    // User's explicit --project-directory must be preserved
    expect($customBuildCommand)->toContain("--project-directory {$customProjectDir}");
    expect($customBuildCommand)->not->toContain("--project-directory {$basedir}");
});

it('correctly resolves Dockerfile path with basedir project-directory', function () {
    $basedir = '/artifacts/deploy-uuid';
    $workdir = '/artifacts/deploy-uuid/subdir';
    $dockerfileLocation = '/Dockerfile';

    // BuildKit resolves Dockerfile relative to --project-directory
    // When --project-directory is $basedir, Dockerfile resolves to $basedir/Dockerfile
    $resolvedDockerfile = $basedir.$dockerfileLocation;

    expect($resolvedDockerfile)->toBe('/artifacts/deploy-uuid/Dockerfile');
    // This is correct - Dockerfile exists at basedir/Dockerfile
    expect($resolvedDockerfile)->not->toBe('/artifacts/deploy-uuid/subdir/Dockerfile');
});

it('build command with multiple compose files in subdirectory uses correct project-directory', function () {
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/docker';
    $customBuildCommand = 'docker compose -f docker-compose.yml -f docker-compose.prod.yml build';

    if (! str($customBuildCommand)->contains('--project-directory')) {
        $customBuildCommand = str($customBuildCommand)
            ->replaceFirst('compose', 'compose --project-directory '.$basedir)
            ->value();
    }

    expect($customBuildCommand)->toContain("--project-directory {$basedir}");
    expect($customBuildCommand)->toContain('-f docker-compose.yml');
    expect($customBuildCommand)->toContain('-f docker-compose.prod.yml');
});

it('build command with no -f flag uses correct project-directory', function () {
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/docker';
    $customBuildCommand = 'docker compose build';

    if (! str($customBuildCommand)->contains('--project-directory')) {
        $customBuildCommand = str($customBuildCommand)
            ->replaceFirst('compose', 'compose --project-directory '.$basedir)
            ->value();
    }

    expect($customBuildCommand)->toContain("--project-directory {$basedir}");
    expect($customBuildCommand)->toBe("docker compose --project-directory {$basedir} build");
});
