<?php

/**
 * Unit tests for GetContainersStatus service aggregation logic (SSH path).
 *
 * These tests verify that the SSH-based status updates (GetContainersStatus)
 * correctly aggregates container statuses for services with multiple containers,
 * using the same logic as PushServerUpdateJob (Sentinel path).
 *
 * This ensures consistency across both status update paths and prevents
 * race conditions where the last container processed wins.
 */
it('implements service multi-container aggregation in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify service container collection property exists
    expect($actionFile)
        ->toContain('protected ?Collection $serviceContainerStatuses;');

    // Verify aggregateServiceContainerStatuses method exists
    expect($actionFile)
        ->toContain('private function aggregateServiceContainerStatuses($services)')
        ->toContain('$this->aggregateServiceContainerStatuses($services);');

    // Verify service aggregation uses same logic as applications
    expect($actionFile)
        ->toContain('$hasUnknown = false;');
});

it('services use same priority as applications in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both aggregation methods should use the same priority logic
    $priorityLogic = <<<'PHP'
                if ($hasUnhealthy) {
                    $aggregatedStatus = 'running (unhealthy)';
                } elseif ($hasUnknown) {
                    $aggregatedStatus = 'running (unknown)';
                } else {
                    $aggregatedStatus = 'running (healthy)';
                }
PHP;

    // Should appear in service aggregation
    expect($actionFile)->toContain($priorityLogic);
});

it('collects service containers before aggregating in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify service containers are collected, not immediately updated
    expect($actionFile)
        ->toContain('$key = $serviceLabelId.\':\'.$subType.\':\'.$subId;')
        ->toContain('$this->serviceContainerStatuses->get($key)->put($containerName, $containerStatus);');

    // Verify aggregation happens before ServiceChecked dispatch
    expect($actionFile)
        ->toContain('$this->aggregateServiceContainerStatuses($services);')
        ->toContain('ServiceChecked::dispatch($this->server->team->id);');
});

it('SSH and Sentinel paths use identical service aggregation logic', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both should track the same status flags
    expect($jobFile)->toContain('$hasUnknown = false;');
    expect($actionFile)->toContain('$hasUnknown = false;');

    // Both should check for unknown status
    expect($jobFile)->toContain('if (str($status)->contains(\'unknown\')) {');
    expect($actionFile)->toContain('if (str($status)->contains(\'unknown\')) {');

    // Both should have elseif for unknown priority
    expect($jobFile)->toContain('} elseif ($hasUnknown) {');
    expect($actionFile)->toContain('} elseif ($hasUnknown) {');
});

it('handles service status updates consistently', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both should parse service key with same format
    expect($jobFile)->toContain('[$serviceId, $subType, $subId] = explode(\':\', $key);');
    expect($actionFile)->toContain('[$serviceId, $subType, $subId] = explode(\':\', $key);');

    // Both should handle excluded containers
    expect($jobFile)->toContain('$excludedContainers = collect();');
    expect($actionFile)->toContain('$excludedContainers = collect();');
});
