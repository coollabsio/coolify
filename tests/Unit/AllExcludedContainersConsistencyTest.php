<?php

/**
 * Unit tests to verify consistent handling of all-excluded containers
 * across PushServerUpdateJob, GetContainersStatus, and ComplexStatusCheck.
 *
 * These tests verify the fix for issue where different code paths handled
 * all-excluded containers inconsistently:
 * - PushServerUpdateJob (Sentinel, ~30s) previously skipped updates
 * - GetContainersStatus (SSH, ~1min) previously skipped updates
 * - ComplexStatusCheck (Multi-server) correctly calculated :excluded status
 *
 * After this fix, all three paths now calculate and return :excluded status
 * consistently, preventing status drift and UI inconsistencies.
 */
it('ensures CalculatesExcludedStatus trait exists with required methods', function () {
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');

    // Verify trait has both status calculation methods
    expect($traitFile)
        ->toContain('trait CalculatesExcludedStatus')
        ->toContain('protected function calculateExcludedStatus(Collection $containers, Collection $excludedContainers): string')
        ->toContain('protected function calculateExcludedStatusFromStrings(Collection $containerStatuses): string')
        ->toContain('protected function getExcludedContainersFromDockerCompose(?string $dockerComposeRaw): Collection');
});

it('ensures ComplexStatusCheck uses CalculatesExcludedStatus trait', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Verify trait is used
    expect($complexStatusCheckFile)
        ->toContain('use App\Traits\CalculatesExcludedStatus;')
        ->toContain('use CalculatesExcludedStatus;');

    // Verify it uses the trait method instead of inline code
    expect($complexStatusCheckFile)
        ->toContain('return $this->calculateExcludedStatus($containers, $excludedContainers);');

    // Verify it uses the trait helper for excluded containers
    expect($complexStatusCheckFile)
        ->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
});

it('ensures PushServerUpdateJob uses CalculatesExcludedStatus trait', function () {
    $pushServerUpdateJobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');

    // Verify trait is used
    expect($pushServerUpdateJobFile)
        ->toContain('use App\Traits\CalculatesExcludedStatus;')
        ->toContain('use CalculatesExcludedStatus;');

    // Verify it calculates excluded status instead of skipping (old behavior: continue)
    expect($pushServerUpdateJobFile)
        ->toContain('// If all containers are excluded, calculate status from excluded containers')
        ->toContain('$aggregatedStatus = $this->calculateExcludedStatusFromStrings($containerStatuses);');

    // Verify it uses the trait helper for excluded containers
    expect($pushServerUpdateJobFile)
        ->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
});

it('ensures PushServerUpdateJob calculates excluded status for applications', function () {
    $pushServerUpdateJobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');

    // In aggregateMultiContainerStatuses, verify the all-excluded scenario
    // calculates status and updates the application
    expect($pushServerUpdateJobFile)
        ->toContain('if ($relevantStatuses->isEmpty()) {')
        ->toContain('$aggregatedStatus = $this->calculateExcludedStatusFromStrings($containerStatuses);')
        ->toContain('if ($aggregatedStatus && $application->status !== $aggregatedStatus) {')
        ->toContain('$application->status = $aggregatedStatus;')
        ->toContain('$application->save();');
});

it('ensures PushServerUpdateJob calculates excluded status for services', function () {
    $pushServerUpdateJobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');

    // Count occurrences - should appear twice (once for applications, once for services)
    $calculateExcludedCount = substr_count(
        $pushServerUpdateJobFile,
        '$aggregatedStatus = $this->calculateExcludedStatusFromStrings($containerStatuses);'
    );

    expect($calculateExcludedCount)->toBe(2, 'Should calculate excluded status for both applications and services');
});

it('ensures GetContainersStatus uses CalculatesExcludedStatus trait', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify trait is used
    expect($getContainersStatusFile)
        ->toContain('use App\Traits\CalculatesExcludedStatus;')
        ->toContain('use CalculatesExcludedStatus;');

    // Verify it calculates excluded status instead of returning null
    expect($getContainersStatusFile)
        ->toContain('// If all containers are excluded, calculate status from excluded containers')
        ->toContain('return $this->calculateExcludedStatusFromStrings($containerStatuses);');

    // Verify it uses the trait helper for excluded containers
    expect($getContainersStatusFile)
        ->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
});

it('ensures GetContainersStatus calculates excluded status for applications', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // In aggregateApplicationStatus, verify the all-excluded scenario returns status
    expect($getContainersStatusFile)
        ->toContain('if ($relevantStatuses->isEmpty()) {')
        ->toContain('return $this->calculateExcludedStatusFromStrings($containerStatuses);');
});

it('ensures GetContainersStatus calculates excluded status for services', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // In aggregateServiceContainerStatuses, verify the all-excluded scenario updates status
    expect($getContainersStatusFile)
        ->toContain('$aggregatedStatus = $this->calculateExcludedStatusFromStrings($containerStatuses);')
        ->toContain('if ($aggregatedStatus) {')
        ->toContain('$statusFromDb = $subResource->status;')
        ->toContain("if (\$statusFromDb !== \$aggregatedStatus) {\n                        \$subResource->update(['status' => \$aggregatedStatus]);");
});

it('ensures excluded status format is consistent across all paths', function () {
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');

    // Trait now delegates to ContainerStatusAggregator and uses appendExcludedSuffix helper
    expect($traitFile)
        ->toContain('use App\\Services\\ContainerStatusAggregator;')
        ->toContain('$aggregator = new ContainerStatusAggregator;')
        ->toContain('private function appendExcludedSuffix(string $status): string');

    // Check that appendExcludedSuffix returns consistent colon format with :excluded suffix
    expect($traitFile)
        ->toContain("return 'degraded:excluded';")
        ->toContain("return 'paused:excluded';")
        ->toContain("return 'starting:excluded';")
        ->toContain("return 'exited';")
        ->toContain('return "$status:excluded";'); // For running:healthy:excluded, running:unhealthy:excluded, etc.
});

it('ensures all three paths check for exclude_from_hc flag consistently', function () {
    // All three should use the trait helper method
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');
    $pushServerUpdateJobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    expect($complexStatusCheckFile)
        ->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');

    expect($pushServerUpdateJobFile)
        ->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');

    expect($getContainersStatusFile)
        ->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');

    // The trait method should check both exclude_from_hc and restart: no
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');
    expect($traitFile)
        ->toContain('$excludeFromHc = data_get($serviceConfig, \'exclude_from_hc\', false);')
        ->toContain('$restartPolicy = data_get($serviceConfig, \'restart\', \'always\');')
        ->toContain('if ($excludeFromHc || $restartPolicy === \'no\') {');
});

it('ensures calculateExcludedStatus uses ContainerStatusAggregator', function () {
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');

    // Check that the trait uses ContainerStatusAggregator service instead of duplicating logic
    expect($traitFile)
        ->toContain('protected function calculateExcludedStatus(Collection $containers, Collection $excludedContainers): string')
        ->toContain('use App\Services\ContainerStatusAggregator;')
        ->toContain('$aggregator = new ContainerStatusAggregator;')
        ->toContain('$aggregator->aggregateFromContainers($excludedOnly)');

    // Check that it has appendExcludedSuffix helper for all states
    expect($traitFile)
        ->toContain('private function appendExcludedSuffix(string $status): string')
        ->toContain("return 'degraded:excluded';")
        ->toContain("return 'paused:excluded';")
        ->toContain("return 'starting:excluded';")
        ->toContain("return 'exited';")
        ->toContain('return "$status:excluded";'); // For running:healthy:excluded
});

it('ensures calculateExcludedStatusFromStrings uses ContainerStatusAggregator', function () {
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');

    // Check that the trait uses ContainerStatusAggregator service instead of duplicating logic
    expect($traitFile)
        ->toContain('protected function calculateExcludedStatusFromStrings(Collection $containerStatuses): string')
        ->toContain('use App\Services\ContainerStatusAggregator;')
        ->toContain('$aggregator = new ContainerStatusAggregator;')
        ->toContain('$aggregator->aggregateFromStrings($containerStatuses)');

    // Check that it has appendExcludedSuffix helper for all states
    expect($traitFile)
        ->toContain('private function appendExcludedSuffix(string $status): string')
        ->toContain("return 'degraded:excluded';")
        ->toContain("return 'paused:excluded';")
        ->toContain("return 'starting:excluded';")
        ->toContain("return 'exited';")
        ->toContain('return "$status:excluded";'); // For running:healthy:excluded
});

it('verifies no code path skips update when all containers excluded', function () {
    $pushServerUpdateJobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // These patterns should NOT exist anymore (old behavior that caused drift)
    expect($pushServerUpdateJobFile)
        ->not->toContain("// If all containers are excluded, don't update status");

    expect($getContainersStatusFile)
        ->not->toContain("// If all containers are excluded, don't update status");

    // Instead, both should calculate excluded status
    expect($pushServerUpdateJobFile)
        ->toContain('// If all containers are excluded, calculate status from excluded containers');

    expect($getContainersStatusFile)
        ->toContain('// If all containers are excluded, calculate status from excluded containers');
});
