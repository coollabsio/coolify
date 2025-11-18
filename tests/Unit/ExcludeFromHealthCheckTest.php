<?php

/**
 * Unit tests to verify that applications and services with all containers
 * excluded from health checks (exclude_from_hc: true) show correct status.
 *
 * These tests verify the fix for the issue where services with all containers
 * excluded would show incorrect "running:healthy" or ":" status, causing
 * broken UI state with active start/stop buttons.
 */
it('ensures ComplexStatusCheck returns exited status when all containers excluded', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Check that when all containers are excluded (relevantContainerCount === 0),
    // the status is set to 'exited:healthy' instead of 'running:healthy'
    expect($complexStatusCheckFile)
        ->toContain("if (\$relevantContainerCount === 0) {\n            return 'exited:healthy';\n        }")
        ->not->toContain("if (\$relevantContainerCount === 0) {\n            return 'running:healthy';\n        }");
});

it('ensures Service model returns exited status when all services excluded', function () {
    $serviceModelFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that when all services are excluded from status checks,
    // the Service model returns 'exited:healthy' instead of ':' (null:null)
    expect($serviceModelFile)
        ->toContain('// If all services are excluded from status checks, return a default exited status')
        ->toContain("if (\$complexStatus === null && \$complexHealth === null) {\n            return 'exited:healthy';\n        }");
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
