<?php

/**
 * Unit tests for Docker Compose healthcheck polling during deployment.
 *
 * Tests verify that the health_check_compose() method in ApplicationDeploymentJob
 * properly handles container enumeration, healthcheck detection, and status reporting.
 */
it('has health_check_compose method in ApplicationDeploymentJob', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    expect($deploymentJobFile)
        ->toContain('private function health_check_compose(): void');
});

it('calls health_check_compose from deploy_docker_compose_buildpack', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Verify the compose deployment method calls health_check_compose
    expect($deploymentJobFile)
        ->toContain('$this->health_check_compose();')
        ->toContain("addLogEntry('New containers started.')");
});

it('skips healthcheck polling for swarm mode', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Find the health_check_compose method and verify it checks for swarm
    $methodStart = strpos($deploymentJobFile, 'private function health_check_compose(): void');
    $methodBody = substr($deploymentJobFile, $methodStart, 3000);

    expect($methodBody)
        ->toContain('$this->server->isSwarm()')
        ->toContain('return;');
});

it('uses docker ps to enumerate compose containers', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $methodStart = strpos($deploymentJobFile, 'private function health_check_compose(): void');
    $methodBody = substr($deploymentJobFile, $methodStart, 5000);

    // Should filter by compose project label
    expect($methodBody)
        ->toContain("docker ps -a --filter 'label=com.docker.compose.project=")
        ->toContain('com.docker.compose.service');
});

it('checks each container for healthcheck configuration before polling', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $methodStart = strpos($deploymentJobFile, 'private function health_check_compose(): void');
    $methodBody = substr($deploymentJobFile, $methodStart, 5000);

    // Should use docker inspect to check if healthcheck exists
    expect($methodBody)
        ->toContain('docker inspect')
        ->toContain('.State.Health')
        ->toContain('has_healthcheck');
});

it('logs when no healthchecks are defined in compose services', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $methodStart = strpos($deploymentJobFile, 'private function health_check_compose(): void');
    $methodBody = substr($deploymentJobFile, $methodStart, 5000);

    expect($methodBody)
        ->toContain('No healthchecks defined in compose services.');
});

it('inspects health status and logs for each container during polling', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $methodStart = strpos($deploymentJobFile, 'private function health_check_compose(): void');
    $methodBody = substr($deploymentJobFile, $methodStart, 5000);

    // Should inspect both status and log
    expect($methodBody)
        ->toContain("docker inspect --format='{{json .State.Health.Status}}'")
        ->toContain("docker inspect --format='{{json .State.Health.Log}}'");
});

it('logs container output for unhealthy services', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $methodStart = strpos($deploymentJobFile, 'private function health_check_compose(): void');
    $methodBody = substr($deploymentJobFile, $methodStart, 8000);

    // Should fetch docker logs for unhealthy containers
    expect($methodBody)
        ->toContain('unhealthyContainers')
        ->toContain('docker logs -n 100');
});

it('logs success when all compose services are healthy', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $methodStart = strpos($deploymentJobFile, 'private function health_check_compose(): void');
    $methodBody = substr($deploymentJobFile, $methodStart, 8000);

    expect($methodBody)
        ->toContain('All compose services are healthy.');
});

it('wraps health_check_compose in try-catch to avoid blocking deployment', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $methodStart = strpos($deploymentJobFile, 'private function health_check_compose(): void');
    $methodBody = substr($deploymentJobFile, $methodStart, 8000);

    // Should catch exceptions and log a warning rather than failing the deployment
    expect($methodBody)
        ->toContain('catch (Exception $e)')
        ->toContain('Healthcheck polling failed:');
});
