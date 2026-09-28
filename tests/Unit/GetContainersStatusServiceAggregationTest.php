<?php

use App\Services\ContainerStatusAggregator;

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

    // Verify service aggregation uses the shared aggregator, like applications
    expect($actionFile)
        ->toContain('use App\\Services\\ContainerStatusAggregator;')
        ->toContain('$aggregator = new ContainerStatusAggregator;');
});

it('services use same priority as applications in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both aggregation methods delegate the priority logic to ContainerStatusAggregator
    expect($actionFile)
        ->toContain('return $aggregator->aggregateFromStrings($relevantStatuses, $maxRestartCount, preserveRestarting: true);')
        ->toContain('$aggregatedStatus = $aggregator->aggregateFromStrings($relevantStatuses, preserveRestarting: true);');

    // The shared aggregator prioritizes unhealthy > unknown > healthy
    $aggregator = new ContainerStatusAggregator;
    expect($aggregator->aggregateFromStrings(collect(['running (healthy)', 'running (unknown)', 'running (unhealthy)'])))->toBe('running:unhealthy')
        ->and($aggregator->aggregateFromStrings(collect(['running (healthy)', 'running (unknown)'])))->toBe('running:unknown')
        ->and($aggregator->aggregateFromStrings(collect(['running (healthy)', 'running (healthy)'])))->toBe('running:healthy');
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

    // Both paths delegate status aggregation to the same shared service
    foreach ([$jobFile, $actionFile] as $file) {
        expect($file)
            ->toContain('use App\\Services\\ContainerStatusAggregator;')
            ->toContain('$aggregator = new ContainerStatusAggregator;')
            ->toContain('$aggregator->aggregateFromStrings($relevantStatuses,')
            ->toContain('preserveRestarting: true);');
    }
});

it('handles service status updates consistently', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both should parse service key with same format
    expect($jobFile)->toContain('[$serviceId, $subType, $subId] = explode(\':\', $key);');
    expect($actionFile)->toContain('[$serviceId, $subType, $subId] = explode(\':\', $key);');

    // Both should handle excluded containers through the shared trait helper
    expect($jobFile)->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
    expect($actionFile)->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
});
