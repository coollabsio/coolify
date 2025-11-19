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

    // Check that when all containers are excluded, the status calculation
    // processes excluded containers and returns status with :excluded suffix
    expect($complexStatusCheckFile)
        ->toContain('// If all containers are excluded, calculate status from excluded containers')
        ->toContain('// but mark it with :excluded to indicate monitoring is disabled')
        ->toContain('if ($relevantContainerCount === 0) {')
        ->toContain("return 'running:excluded';")
        ->toContain("return 'degraded:excluded';")
        ->toContain("return 'exited:excluded';");
});

it('ensures Service model returns excluded status when all services excluded', function () {
    $serviceModelFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that when all services are excluded from status checks,
    // the Service model calculates real status and returns it with :excluded suffix
    expect($serviceModelFile)
        ->toContain('// If all services are excluded from status checks, calculate status from excluded containers')
        ->toContain('// but mark it with :excluded to indicate monitoring is disabled')
        ->toContain('if (! $hasNonExcluded && ($complexStatus === null && $complexHealth === null)) {')
        ->toContain('// Calculate status from excluded containers')
        ->toContain('return "{$excludedStatus}:excluded";')
        ->toContain("return 'exited:excluded';");
});

it('ensures Service model returns unknown:excluded when no containers exist', function () {
    $serviceModelFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that when a service has no applications or databases at all,
    // the Service model returns 'unknown:excluded' instead of 'exited:excluded'
    // This prevents misleading status display when containers don't exist
    expect($serviceModelFile)
        ->toContain('// If no status was calculated at all (no containers exist), return unknown')
        ->toContain('if ($excludedStatus === null && $excludedHealth === null) {')
        ->toContain("return 'unknown:excluded';");
});

it('ensures GetContainersStatus returns null when all containers excluded', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Check that when all containers are excluded, the aggregateApplicationStatus
    // method returns null to avoid updating status
    expect($getContainersStatusFile)
        ->toContain('// If all containers are excluded, don\'t update status')
        ->toContain("if (\$relevantStatuses->isEmpty()) {\n            return null;\n        }");
});

it('ensures exclude_from_hc flag is properly checked in ComplexStatusCheck', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Verify that exclude_from_hc is properly parsed from docker-compose
    expect($complexStatusCheckFile)
        ->toContain('$excludeFromHc = data_get($serviceConfig, \'exclude_from_hc\', false);')
        ->toContain('if ($excludeFromHc || $restartPolicy === \'no\') {')
        ->toContain('$excludedContainers->push($serviceName);');
});

it('ensures exclude_from_hc flag is properly checked in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify that exclude_from_hc is properly parsed from docker-compose
    expect($getContainersStatusFile)
        ->toContain('$excludeFromHc = data_get($serviceConfig, \'exclude_from_hc\', false);')
        ->toContain('if ($excludeFromHc || $restartPolicy === \'no\') {')
        ->toContain('$excludedContainers->push($serviceName);');
});

it('ensures UI displays excluded status correctly in status component', function () {
    $servicesStatusFile = file_get_contents(__DIR__.'/../../resources/views/components/status/services.blade.php');

    // Verify that the status component detects :excluded suffix and shows monitoring disabled message
    expect($servicesStatusFile)
        ->toContain('$isExcluded = str($complexStatus)->endsWith(\':excluded\');')
        ->toContain('$displayStatus = $isExcluded ? str($complexStatus)->beforeLast(\':excluded\') : $complexStatus;')
        ->toContain('(Monitoring Disabled)');
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
