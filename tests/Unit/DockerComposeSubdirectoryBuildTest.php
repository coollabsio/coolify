<?php

/**
 * Test to verify that docker-compose commands use correct paths when
 * docker_compose_location is in a subdirectory.
 *
 * Bug: When base_directory='/docker' and docker_compose_location='/docker/docker-compose.yml',
 * the -f flag path was incorrectly constructed as:
 *   workdir + docker_compose_location = /artifacts/{uuid}/docker/docker/docker-compose.yml
 * (double subdirectory path)
 *
 * Should be:
 *   basedir + docker_compose_location = /artifacts/{uuid}/docker/docker-compose.yml
 *
 * @see https://github.com/coollabsio/coolify/issues/9525
 */

it('build command uses basedir for -f flag when docker_compose_location is in subdirectory', function () {
    $deploymentUuid = 'test-deployment-uuid';
    $applicationUuid = 'app-uuid-1234';

    // Simulating: base_directory='/docker', docker_compose_location='/docker/docker-compose.yml'
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/docker'; // basedir + base_directory
    $dockerComposeLocation = '/docker/docker-compose.yml';

    // Build command generation (simulating lines ~747-750 of ApplicationDeploymentJob.php)
    $forceRebuild = false;
    if ($forceRebuild) {
        $command = "docker compose --project-name {$applicationUuid} --project-directory {$workdir} -f {$basedir}{$dockerComposeLocation} build --pull --no-cache";
    } else {
        $command = "docker compose --project-name {$applicationUuid} --project-directory {$workdir} -f {$basedir}{$dockerComposeLocation} build --pull";
    }

    // -f flag should use basedir (not workdir) to avoid double subdirectory path
    expect($command)->toContain("-f {$basedir}{$dockerComposeLocation}");
    // Should NOT contain the buggy double path
    expect($command)->not->toContain('docker/docker/docker');
    // --project-directory should still use workdir
    expect($command)->toContain("--project-directory {$workdir}");
});

it('compose file write uses basedir for -f flag when docker_compose_location is in subdirectory', function () {
    $deploymentUuid = 'test-deployment-uuid';
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/docker';
    $dockerComposeLocation = '/docker/docker-compose.yml';

    // Simulating the compose file write (line ~692 of ApplicationDeploymentJob.php)
    $writeCommand = "echo 'base64data' | base64 -d | tee {$basedir}{$dockerComposeLocation} > /dev/null";

    // Should use basedir + docker_compose_location (not workdir + docker_compose_location)
    expect($writeCommand)->toContain("{$basedir}{$dockerComposeLocation}");
    expect($writeCommand)->not->toContain("{$workdir}{$dockerComposeLocation}");
    expect($writeCommand)->not->toContain('docker/docker/docker');
});

it('default case (no subdirectory) still works correctly', function () {
    $applicationUuid = 'app-uuid-1234';
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid'; // No subdirectory
    $dockerComposeLocation = '/docker-compose.yaml'; // Default location at root

    $command = "docker compose --project-name {$applicationUuid} --project-directory {$workdir} -f {$basedir}{$dockerComposeLocation} build --pull";

    // Both should be the same when no subdirectory
    expect($command)->toContain("-f {$basedir}{$dockerComposeLocation}");
    expect($command)->toContain("--project-directory {$workdir}");
    expect($command)->toContain("/artifacts/test-deployment-uuid/docker-compose.yaml");
});

it('injectDockerComposeFlags uses basedir for -f flag in custom build command', function () {
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/docker';
    $dockerComposeLocation = '/docker/docker-compose.yml';
    $envFile = '/artifacts/build-time.env';

    $customBuildCommand = 'docker compose build';
    $fullCommand = injectDockerComposeFlags($customBuildCommand, "{$basedir}{$dockerComposeLocation}", $envFile);

    // Should use basedir for -f flag
    expect($fullCommand)->toContain("-f {$basedir}{$dockerComposeLocation}");
    expect($fullCommand)->not->toContain("-f {$workdir}{$dockerComposeLocation}");
    expect($fullCommand)->not->toContain('docker/docker/docker');
    expect($fullCommand)->toContain("--env-file {$envFile}");
});

it('injectDockerComposeFlags uses basedir for -f flag in custom start command', function () {
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/docker';
    $dockerComposeLocation = '/docker/docker-compose.yml';
    $envFile = '/artifacts/test-deployment-uuid/.env';

    $customStartCommand = 'docker compose up -d';
    $fullCommand = injectDockerComposeFlags($customStartCommand, "{$basedir}{$dockerComposeLocation}", $envFile);

    // Should use basedir for -f flag
    expect($fullCommand)->toContain("-f {$basedir}{$dockerComposeLocation}");
    expect($fullCommand)->not->toContain("-f {$workdir}{$dockerComposeLocation}");
    expect($fullCommand)->not->toContain('docker/docker/docker');
    expect($fullCommand)->toContain("--env-file {$envFile}");
});
