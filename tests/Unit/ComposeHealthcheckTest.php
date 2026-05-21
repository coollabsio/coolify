<?php

beforeEach(function () {
    $this->deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $methodStart = strpos($this->deploymentJobFile, 'private function health_check_compose(): void');
    $this->methodBody = substr($this->deploymentJobFile, $methodStart, 12000);
});

it('has health_check_compose method in ApplicationDeploymentJob', function () {
    expect($this->deploymentJobFile)
        ->toContain('private function health_check_compose(): void');
});

it('calls health_check_compose from deploy_docker_compose_buildpack', function () {
    expect($this->deploymentJobFile)
        ->toContain('$this->health_check_compose();')
        ->toContain("addLogEntry('New containers started.')");
});

it('skips healthcheck polling for swarm mode', function () {
    expect($this->methodBody)
        ->toContain('$this->server->isSwarm()')
        ->toContain('return;');
});

it('uses docker ps to enumerate compose containers', function () {
    expect($this->methodBody)
        ->toContain("docker ps -a --filter 'label=com.docker.compose.project=")
        ->toContain('com.docker.compose.service');
});

it('validates container names from docker ps output before using them', function () {
    expect($this->methodBody)
        ->toContain('ValidationPatterns::isValidContainerName');
});

it('checks each container for healthcheck configuration before polling', function () {
    expect($this->methodBody)
        ->toContain('docker inspect')
        ->toContain('.State.Health')
        ->toContain('has_healthcheck');
});

it('logs when no healthchecks are defined in compose services', function () {
    expect($this->methodBody)
        ->toContain('No healthchecks defined in compose services.');
});

it('inspects health status and logs for each container during polling', function () {
    expect($this->methodBody)
        ->toContain("docker inspect --format='{{json .State.Health.Status}}'")
        ->toContain("docker inspect --format='{{json .State.Health.Log}}'");
});

it('logs container output for unhealthy services', function () {
    expect($this->methodBody)
        ->toContain('unhealthyContainers')
        ->toContain('docker logs -n 100');
});

it('logs success when all compose services are healthy', function () {
    expect($this->methodBody)
        ->toContain('All compose services are healthy.');
});

it('wraps health_check_compose in try-catch to avoid blocking deployment', function () {
    expect($this->methodBody)
        ->toContain('catch (Exception $e)')
        ->toContain('Healthcheck polling failed:');
});

it('uses --remove-orphans flag on all docker compose up commands', function () {
    $composeMethodStart = strpos($this->deploymentJobFile, 'private function deploy_docker_compose_buildpack()');
    $composeMethodBody = substr($this->deploymentJobFile, $composeMethodStart, 16000);

    $upDOccurrences = preg_match_all('/up -d/', $composeMethodBody, $matches, PREG_OFFSET_CAPTURE);
    $removeOrphansOccurrences = preg_match_all('/up -d --remove-orphans/', $composeMethodBody, $matches2);

    expect($upDOccurrences)->toBeGreaterThan(0);
    expect($removeOrphansOccurrences)->toBe($upDOccurrences);
});

it('derives healthcheck timings from container Docker config instead of Application model', function () {
    expect($this->methodBody)
        ->toContain("docker inspect --format='{{json .Config.Healthcheck}}'")
        ->toContain('StartPeriod')
        ->toContain('Interval')
        ->toContain('Retries')
        ->toContain('1_000_000_000');

    expect($this->methodBody)
        ->not->toContain('$this->application->health_check_start_period')
        ->not->toContain('$this->application->health_check_retries')
        ->not->toContain('$this->application->health_check_interval');
});

it('caps healthcheck wait times to prevent unbounded deployment blocking', function () {
    expect($this->methodBody)
        ->toContain('min($maxStartPeriod, 300)')
        ->toContain('min(max($maxInterval, 5), 60)')
        ->toContain('min(max($maxRetries + 2, 3), 30)');
});

it('checks for deployment cancellation during healthcheck sleep loops', function () {
    expect($this->methodBody)
        ->toContain('$this->checkForCancellation()');
});

it('uses ignore_errors on docker ps command for container enumeration', function () {
    // The docker ps command block should include ignore_errors
    $dockerPsStart = strpos($this->methodBody, 'docker ps -a');
    $dockerPsBlock = substr($this->methodBody, $dockerPsStart, 400);

    expect($dockerPsBlock)
        ->toContain("'ignore_errors' => true");
});

it('logs a message when no containers are found for healthcheck polling', function () {
    expect($this->methodBody)
        ->toContain('No compose containers found for healthcheck polling.');
});

it('rethrows DeploymentException from catch block so cancellation is not swallowed', function () {
    // The catch block must rethrow DeploymentException before the generic Exception catch
    $catchBlockStart = strpos($this->methodBody, 'catch (DeploymentException');
    $genericCatchStart = strpos($this->methodBody, 'catch (Exception $e)');

    expect($catchBlockStart)->not->toBeFalse('DeploymentException catch block must exist');
    expect($catchBlockStart)->toBeLessThan($genericCatchStart, 'DeploymentException catch must come before generic Exception catch');

    // Extract the DeploymentException catch body and verify it rethrows
    $deploymentCatchBody = substr($this->methodBody, $catchBlockStart, $genericCatchStart - $catchBlockStart);
    expect($deploymentCatchBody)->toContain('throw $e');
});

it('handles empty docker inspect status by skipping the container', function () {
    expect($this->methodBody)
        ->toContain('Could not retrieve healthcheck status for')
        ->toContain('Container may have stopped.');
});
