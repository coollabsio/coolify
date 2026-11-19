<?php

/**
 * Test to verify that docker-compose custom start commands use the correct
 * execution context based on the preserveRepository setting.
 *
 * When preserveRepository is enabled, the compose file and .env file are
 * written to the host at /data/coolify/applications/{uuid}/. The start
 * command must run on the host (not inside the helper container) so it
 * can access these files.
 *
 * When preserveRepository is disabled, the files are inside the helper
 * container at /artifacts/{uuid}/, so the command must run inside the
 * container via executeInDocker().
 *
 * @see https://github.com/coollabsio/coolify/issues/8417
 */
it('generates host command (not executeInDocker) when preserveRepository is true', function () {
    $deploymentUuid = 'test-deployment-uuid';
    $serverWorkdir = '/data/coolify/applications/app-uuid';
    $basedir = '/artifacts/test-deployment-uuid';
    $preserveRepository = true;

    $startCommand = 'docker compose -f /data/coolify/applications/app-uuid/compose.yml --env-file /data/coolify/applications/app-uuid/.env --profile all up -d';

    // Simulate the logic from ApplicationDeploymentJob::deploy_docker_compose_buildpack()
    if ($preserveRepository) {
        $command = "cd {$serverWorkdir} && {$startCommand}";
    } else {
        $command = executeInDocker($deploymentUuid, "cd {$basedir} && {$startCommand}");
    }

    // When preserveRepository is true, the command should NOT be wrapped in executeInDocker
    expect($command)->not->toContain('docker exec');
    expect($command)->toStartWith("cd {$serverWorkdir}");
    expect($command)->toContain($startCommand);
});

it('generates executeInDocker command when preserveRepository is false', function () {
    $deploymentUuid = 'test-deployment-uuid';
    $serverWorkdir = '/data/coolify/applications/app-uuid';
    $basedir = '/artifacts/test-deployment-uuid';
    $workdir = '/artifacts/test-deployment-uuid/backend';
    $preserveRepository = false;

    $startCommand = 'docker compose -f /artifacts/test-deployment-uuid/backend/compose.yml --env-file /artifacts/test-deployment-uuid/backend/.env --profile all up -d';

    // Simulate the logic from ApplicationDeploymentJob::deploy_docker_compose_buildpack()
    if ($preserveRepository) {
        $command = "cd {$serverWorkdir} && {$startCommand}";
    } else {
        $command = executeInDocker($deploymentUuid, "cd {$basedir} && {$startCommand}");
    }

    // When preserveRepository is false, the command SHOULD be wrapped in executeInDocker
    expect($command)->toContain('docker exec');
    expect($command)->toContain($deploymentUuid);
    expect($command)->toContain("cd {$basedir}");
});

it('uses host paths for env-file when preserveRepository is true', function () {
    $serverWorkdir = '/data/coolify/applications/app-uuid';
    $composeLocation = '/compose.yml';
    $preserveRepository = true;

    $workdirPath = $preserveRepository ? $serverWorkdir : '/artifacts/deployment-uuid/backend';
    $startCommand = injectDockerComposeFlags(
        'docker compose --profile all up -d',
        "{$workdirPath}{$composeLocation}",
        "{$workdirPath}/.env"
    );

    // Verify the injected paths point to the host filesystem
    expect($startCommand)->toContain("--env-file {$serverWorkdir}/.env");
    expect($startCommand)->toContain("-f {$serverWorkdir}{$composeLocation}");
});

it('injects --project-directory with host path when preserveRepository is true', function () {
    $serverWorkdir = '/data/coolify/applications/app-uuid';
    $containerWorkdir = '/artifacts/deployment-uuid';
    $preserveRepository = true;

    $customStartCommand = 'docker compose -f docker-compose.yaml -f docker-compose.prod.yaml up -d';

    // Simulate the --project-directory injection from deploy_docker_compose_buildpack()
    if (! str($customStartCommand)->contains('--project-directory')) {
        $projectDir = $preserveRepository ? $serverWorkdir : $containerWorkdir;
        $customStartCommand = str($customStartCommand)->replaceFirst('compose', 'compose --project-directory '.$projectDir)->value();
    }

    // When preserveRepository is true, --project-directory must point to host path
    expect($customStartCommand)->toContain("--project-directory {$serverWorkdir}");
    expect($customStartCommand)->not->toContain('/artifacts/');
});

it('injects --project-directory with container path when preserveRepository is false', function () {
    $serverWorkdir = '/data/coolify/applications/app-uuid';
    $containerWorkdir = '/artifacts/deployment-uuid';
    $preserveRepository = false;

    $customStartCommand = 'docker compose -f docker-compose.yaml -f docker-compose.prod.yaml up -d';

    // Simulate the --project-directory injection from deploy_docker_compose_buildpack()
    if (! str($customStartCommand)->contains('--project-directory')) {
        $projectDir = $preserveRepository ? $serverWorkdir : $containerWorkdir;
        $customStartCommand = str($customStartCommand)->replaceFirst('compose', 'compose --project-directory '.$projectDir)->value();
    }

    // When preserveRepository is false, --project-directory must point to container path
    expect($customStartCommand)->toContain("--project-directory {$containerWorkdir}");
    expect($customStartCommand)->not->toContain('/data/coolify/applications/');
});

it('does not override explicit --project-directory in custom start command', function () {
    $customProjectDir = '/custom/path';
    $customStartCommand = "docker compose --project-directory {$customProjectDir} up -d";

    // Simulate the --project-directory injection — should be skipped
    if (! str($customStartCommand)->contains('--project-directory')) {
        $customStartCommand = str($customStartCommand)->replaceFirst('compose', 'compose --project-directory /should-not-appear')->value();
    }

    expect($customStartCommand)->toContain("--project-directory {$customProjectDir}");
    expect($customStartCommand)->not->toContain('/should-not-appear');
});

it('uses container paths for env-file when preserveRepository is false', function () {
    $workdir = '/artifacts/deployment-uuid/backend';
    $composeLocation = '/compose.yml';
    $preserveRepository = false;
    $serverWorkdir = '/data/coolify/applications/app-uuid';

    $workdirPath = $preserveRepository ? $serverWorkdir : $workdir;
    $startCommand = injectDockerComposeFlags(
        'docker compose --profile all up -d',
        "{$workdirPath}{$composeLocation}",
        "{$workdirPath}/.env"
    );

    // Verify the injected paths point to the container filesystem
    expect($startCommand)->toContain("--env-file {$workdir}/.env");
    expect($startCommand)->toContain("-f {$workdir}{$composeLocation}");
    expect($startCommand)->not->toContain('/data/coolify/applications/');
});
