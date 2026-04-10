<?php

/**
 * Unit tests to verify that applications and services with all containers
 * excluded from health checks (exclude_from_hc: true) show correct status.
 *
 * These tests verify the fix for the issue where services with all containers
 * excluded would show incorrect status, causing broken UI state.
 *
 * The fix now returns status with :excluded suffix to show real container state
 * while indicating monitoring is disabled (e.g., "running:excluded").
 */
it('ensures ComplexStatusCheck returns excluded status when all containers excluded', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Check that when all containers are excluded, ComplexStatusCheck uses the trait
    expect($complexStatusCheckFile)
        ->toContain('// If all containers are excluded, calculate status from excluded containers')
        ->toContain('// but mark it with :excluded to indicate monitoring is disabled')
        ->toContain('if ($relevantContainers->isEmpty()) {')
        ->toContain('return $this->calculateExcludedStatus($containers, $excludedContainers);');

    // Check that the trait uses ContainerStatusAggregator and appends :excluded suffix
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');
    expect($traitFile)
        ->toContain('ContainerStatusAggregator')
        ->toContain('appendExcludedSuffix')
        ->toContain('$aggregator->aggregateFromContainers($excludedOnly)')
        ->toContain("return 'degraded:excluded';")
        ->toContain("return 'paused:excluded';")
        ->toContain("return 'exited';")
        ->toContain('return "$status:excluded";'); // For running:healthy:excluded
});

it('ensures Service model returns excluded status when all services excluded', function () {
    $serviceModelFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that when all services are excluded from status checks,
    // the Service model calculates real status and returns it with :excluded suffix
    expect($serviceModelFile)
        ->toContain('exclude_from_status')
        ->toContain(':excluded')
        ->toContain('CalculatesExcludedStatus');
});

it('ensures Service model returns unknown:unknown:excluded when no containers exist', function () {
    $serviceModelFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that when a service has no applications or databases at all,
    // the Service model returns 'unknown:unknown:excluded' instead of 'exited'
    // This prevents misleading status display when containers don't exist
    expect($serviceModelFile)
        ->toContain('// If no status was calculated at all (no containers exist), return unknown')
        ->toContain('if ($excludedStatus === null && $excludedHealth === null) {')
        ->toContain("return 'unknown:unknown:excluded';");
});

it('ensures GetContainersStatus calculates excluded status when all containers excluded', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Check that when all containers are excluded, the aggregateApplicationStatus
    // method calculates and returns status with :excluded suffix
    expect($getContainersStatusFile)
        ->toContain('// If all containers are excluded, calculate status from excluded containers')
        ->toContain('if ($relevantStatuses->isEmpty()) {')
        ->toContain('return $this->calculateExcludedStatusFromStrings($containerStatuses);');
});

it('ensures exclude_from_hc flag is properly checked in ComplexStatusCheck', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Verify that exclude_from_hc is parsed using trait helper
    expect($complexStatusCheckFile)
        ->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
});

it('ensures exclude_from_hc flag is properly checked in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify that exclude_from_hc is parsed using trait helper
    expect($getContainersStatusFile)
        ->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
});

it('ensures UI displays excluded status correctly in status component', function () {
    $servicesStatusFile = file_get_contents(__DIR__.'/../../resources/views/components/status/services.blade.php');

    // Verify that the status component uses formatContainerStatus helper to display status
    expect($servicesStatusFile)
        ->toContain('formatContainerStatus($complexStatus)');
});

it('ensures UI handles excluded status in service heading buttons', function () {
    $headingFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/heading.blade.php');

    // Verify that the heading properly handles running/degraded/exited status with :excluded suffix
    // The logic should use contains() to match the base status (running, degraded, exited)
    // which will work for both regular statuses and :excluded suffixed ones
    expect($headingFile)
        ->toContain('str($service->status)->contains(\'running\')')
        ->toContain('str($service->status)->contains(\'degraded\')')
        ->toContain('str($service->status)->contains(\'exited\')');
});

/**
 * Unit tests for YAML validation in CalculatesExcludedStatus trait
 */
it('ensures YAML validation has proper exception handling for parse errors', function () {
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');

    // Verify that ParseException is imported and caught separately from generic Exception
    expect($traitFile)
        ->toContain('use Symfony\Component\Yaml\Exception\ParseException')
        ->toContain('use Illuminate\Support\Facades\Log')
        ->toContain('} catch (ParseException $e) {')
        ->toContain('} catch (\Exception $e) {');
});

it('ensures YAML validation logs parse errors with context', function () {
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');

    // Verify that parse errors are logged with useful context (error message, line, snippet)
    expect($traitFile)
        ->toContain('Log::warning(\'Failed to parse Docker Compose YAML for health check exclusions\'')
        ->toContain('\'error\' => $e->getMessage()')
        ->toContain('\'line\' => $e->getParsedLine()')
        ->toContain('\'snippet\' => $e->getSnippet()');
});

it('ensures YAML validation logs unexpected errors', function () {
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');

    // Verify that unexpected errors are logged with error level
    expect($traitFile)
        ->toContain('Log::error(\'Unexpected error parsing Docker Compose YAML\'')
        ->toContain('\'trace\' => $e->getTraceAsString()');
});

it('ensures YAML validation checks structure after parsing', function () {
    $traitFile = file_get_contents(__DIR__.'/../../app/Traits/CalculatesExcludedStatus.php');

    // Verify that parsed result is validated to be an array
    expect($traitFile)
        ->toContain('if (! is_array($dockerCompose)) {')
        ->toContain('Log::warning(\'Docker Compose YAML did not parse to array\'');

    // Verify that services is validated to be an array
    expect($traitFile)
        ->toContain('if (! is_array($services)) {')
        ->toContain('Log::warning(\'Docker Compose services is not an array\'');
});
