<?php

function dockerComposeDeploymentMethodSource(): string
{
    $source = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');
    $start = strpos($source, 'private function deploy_docker_compose_buildpack()');
    $end = strpos($source, 'private function deploy_dockerfile_buildpack()', $start);

    return substr($source, $start, $end - $start);
}

it('lets compose reconcile existing containers without a pre-stop', function () {
    expect(dockerComposeDeploymentMethodSource())
        ->not->toContain('$this->stop_running_container(force: true);');
});

it('explicitly removes stale production services while preserving legacy previews', function () {
    $method = dockerComposeDeploymentMethodSource();

    expect($method)
        ->toContain('$legacyPreviewContainersExist = $this->legacyComposePreviewContainersExist();')
        ->toContain('$removeOrphans = $legacyPreviewContainersExist ? \'\' : \' --remove-orphans\';')
        ->toContain('$this->removeStaleProductionComposeContainers($composeProjectName, array_keys(data_get($composeFile, \'services\', [])));')
        ->not->toContain(' up -d --remove-orphans');
});

it('stale production cleanup excludes containers belonging to legacy previews', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');
    $start = strpos($source, 'private function removeStaleProductionComposeContainers(');
    $end = strpos($source, 'private function ', $start + 20);
    $method = substr($source, $start, $end - $start);

    expect($method)
        ->toContain('$pullRequestId !== null && $pullRequestId !== \'\' && $pullRequestId !== \'0\'')
        ->toContain("dockerContainerLabel(\$container, 'com.docker.compose.service')")
        ->toContain('docker rm -f {$containerId}');
});

it('adds orphan removal to a custom compose up command', function () {
    expect(injectDockerComposeRemoveOrphans('docker compose up -d'))
        ->toBe('docker compose up --remove-orphans -d');
});

it('adds orphan removal to a bare custom compose up command', function () {
    expect(injectDockerComposeRemoveOrphans('docker compose up'))
        ->toBe('docker compose up --remove-orphans');
});

it('does not duplicate orphan removal in a custom compose up command', function () {
    expect(injectDockerComposeRemoveOrphans('docker compose up -d --remove-orphans'))
        ->toBe('docker compose up -d --remove-orphans');
});

it('does not add orphan removal while legacy previews share the project', function () {
    expect(injectDockerComposeRemoveOrphans('docker compose up -d', false))
        ->toBe('docker compose up -d');
});

it('leaves custom compose commands without up unchanged', function () {
    expect(injectDockerComposeRemoveOrphans('docker compose pull'))
        ->toBe('docker compose pull');
});

it('adds orphan removal to the compose up segment of a chained command', function () {
    expect(injectDockerComposeRemoveOrphans('docker compose pull && docker compose up -d'))
        ->toBe('docker compose pull && docker compose up --remove-orphans -d');
});

it('reconciles services in both custom compose start branches', function () {
    expect(substr_count(dockerComposeDeploymentMethodSource(), 'injectDockerComposeRemoveOrphans($start_command, ! $legacyPreviewContainersExist)'))
        ->toBe(2);
});

it('migrates a preview before production can remove legacy orphans', function () {
    $method = dockerComposeDeploymentMethodSource();

    expect($method)
        ->toContain('if ($this->pull_request_id !== 0 && $legacyPreviewContainersExist) {')
        ->toContain('$this->removeHealthyLegacyComposePreviewContainers($composeProjectName, $composeFile);');

    expect(strpos($method, '$this->removeHealthyLegacyComposePreviewContainers($composeProjectName, $composeFile);'))
        ->toBeGreaterThan(strrpos($method, 'New container started.'));
});

it('accepts successfully completed one-shot services during preview migration', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');
    $start = strpos($source, 'private function composeContainersAreHealthy(');
    $end = strpos($source, 'private function ', $start + 20);
    $method = substr($source, $start, $end - $start);

    expect($method)
        ->toContain("data_get(\$service, 'restart') === 'no'")
        ->toContain("'exited 0'")
        ->toContain("'running'")
        ->toContain("'running healthy'");
});

it('keeps legacy previews when production deploys first', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');
    $start = strpos($source, 'private function legacyComposePreviewContainersExist()');
    $end = strpos($source, 'private function ', $start + 20);
    $method = substr($source, $start, $end - $start);

    expect($method)
        ->toContain('com.docker.compose.project={$this->application->uuid}')
        ->toContain('$pullRequestId !== \'0\'')
        ->toContain("'save' => 'legacy_compose_preview_containers'");
});

it('removes a legacy preview only after its replacement is healthy', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');
    $start = strpos($source, 'private function removeHealthyLegacyComposePreviewContainers(');
    $end = strpos($source, 'private function ', $start + 20);
    $method = substr($source, $start, $end - $start);

    expect(strpos($method, '$this->composeContainersAreHealthy($replacementContainerIds)'))
        ->toBeLessThan(strpos($method, "'legacy_compose_preview_container_ids'"))
        ->and($method)
        ->toContain('docker rm -f');
});
