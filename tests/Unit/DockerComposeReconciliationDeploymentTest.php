<?php

use App\Jobs\ApplicationDeploymentJob;
use Illuminate\Support\Collection;
use PHPUnit\Framework\AssertionFailedError;

function sourceBetween(string $path, string $startMarker, string $endMarker): string
{
    $source = file_get_contents($path);

    if ($source === false) {
        throw new AssertionFailedError("Unable to read source file: {$path}");
    }

    $start = strpos($source, $startMarker);

    if ($start === false) {
        throw new AssertionFailedError("Start marker not found in {$path}: {$startMarker}");
    }

    $end = strpos($source, $endMarker, $start + strlen($startMarker));

    if ($end === false) {
        throw new AssertionFailedError("End marker not found in {$path}: {$endMarker}");
    }

    return substr($source, $start, $end - $start);
}

function dockerComposeDeploymentMethodSource(): string
{
    return sourceBetween(
        __DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php',
        'private function deploy_docker_compose_buildpack(): void',
        'private function deploy_dockerfile_buildpack()',
    );
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
        ->toContain('$this->removeStaleProductionComposeContainers($composeProjectName, $this->currentComposeServiceNames($composeFile));')
        ->not->toContain(' up -d --remove-orphans');
});

it('gets current Compose service names from raw and parsed payloads', function (array|string|Collection $composeFile) {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    expect((fn () => $this->currentComposeServiceNames($composeFile))->call($job))
        ->toBe(['web', 'worker']);
})->with([
    'raw YAML' => "services:\n  web:\n    image: nginx\n  worker:\n    image: busybox\n",
    'parsed collection' => collect([
        'services' => [
            'web' => ['image' => 'nginx'],
            'worker' => ['image' => 'busybox'],
        ],
    ]),
]);

it('stale production cleanup excludes containers belonging to legacy previews', function () {
    $method = sourceBetween(
        __DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php',
        'private function removeStaleProductionComposeContainers(',
        'private function ',
    );

    expect($method)
        ->toContain('$pullRequestId !== null && $pullRequestId !== \'\' && $pullRequestId !== \'0\'')
        ->toContain("dockerContainerLabel(\$container, 'com.docker.compose.service')")
        ->toContain('docker rm -f {$containerId}');
});

it('adds orphan removal to a custom compose up command', function () {
    expect(injectDockerComposeRemoveOrphans('docker compose up -d'))
        ->toBe('docker compose up --remove-orphans -d');
});

it('preserves option values containing up when adding orphan removal', function () {
    expect(injectDockerComposeRemoveOrphans('docker compose -f /artifacts/up.yml up -d'))
        ->toBe('docker compose -f /artifacts/up.yml up --remove-orphans -d');
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
    $method = sourceBetween(
        __DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php',
        'private function composeContainersAreHealthy(',
        'private function ',
    );

    expect($method)
        ->toContain("data_get(\$service, 'restart') === 'no'")
        ->toContain("'exited 0'")
        ->toContain("'running'")
        ->toContain("'running healthy'");
});

it('keeps legacy previews when production deploys first', function () {
    $method = sourceBetween(
        __DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php',
        'private function legacyComposePreviewContainersExist()',
        'private function ',
    );

    expect($method)
        ->toContain('com.docker.compose.project={$this->application->uuid}')
        ->toContain('$pullRequestId !== \'0\'')
        ->toContain("'save' => 'legacy_compose_preview_containers'");
});

it('removes a legacy preview only after its replacement is healthy', function () {
    $method = sourceBetween(
        __DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php',
        'private function removeHealthyLegacyComposePreviewContainers(',
        'private function ',
    );

    $healthCheckPosition = strpos($method, '$this->composeContainersAreHealthy($replacementContainerIds, $composeFile)');
    $legacyContainerCleanupPosition = strpos($method, "'legacy_compose_preview_container_ids'");

    expect($healthCheckPosition)
        ->not->toBeFalse()
        ->and($legacyContainerCleanupPosition)
        ->not->toBeFalse()
        ->and($healthCheckPosition)
        ->toBeLessThan($legacyContainerCleanupPosition)
        ->and($method)
        ->toContain('docker rm -f');
});

it('uses the application compose project name when deleting volumes', function () {
    $method = sourceBetween(
        __DIR__.'/../../app/Models/Application.php',
        'public function deleteVolumes()',
        'public function deleteConnectedNetworks()',
    );

    expect($method)
        ->toContain('$projectName = generateDockerComposeProjectName($this->uuid);')
        ->toContain('docker compose --project-name {$projectName} down -v');
});
