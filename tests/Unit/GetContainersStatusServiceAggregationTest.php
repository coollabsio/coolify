<?php

use App\Services\ContainerStatusAggregator;

it('services use same priority as applications in SSH path', function () {
    // The shared aggregator prioritizes unhealthy > unknown > healthy
    $aggregator = new ContainerStatusAggregator;
    expect($aggregator->aggregateFromStrings(collect(['running (healthy)', 'running (unknown)', 'running (unhealthy)'])))->toBe('running:unhealthy')
        ->and($aggregator->aggregateFromStrings(collect(['running (healthy)', 'running (unknown)'])))->toBe('running:unknown')
        ->and($aggregator->aggregateFromStrings(collect(['running (healthy)', 'running (healthy)'])))->toBe('running:healthy');
});
