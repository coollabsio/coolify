<?php

use App\Services\FleetMetricsAggregator;

it('sums series by bucket timestamp and sorts ascending', function () {
    $merged = FleetMetricsAggregator::sumSeriesByBucket([
        [[2000, 5.0], [1000, 1.0]],
        [[1000, 2.0], [3000, 9.0]],
    ], 'sum');

    expect($merged)->toBe([[1000, 3.0], [2000, 5.0], [3000, 9.0]]);
});

it('averages series across servers per bucket', function () {
    $merged = FleetMetricsAggregator::sumSeriesByBucket([
        [[1000, 20.0]],
        [[1000, 40.0]],
    ], 'avg');

    expect($merged)->toBe([[1000, 30.0]]);
});

it('ranks containers by the selected metric and caps the list', function () {
    $containers = [
        ['id' => 'a', 'cpu' => 10.0, 'memUsed' => 500, 'diskBytes' => 5, 'net' => 1.0],
        ['id' => 'b', 'cpu' => 90.0, 'memUsed' => 100, 'diskBytes' => 9, 'net' => 2.0],
        ['id' => 'c', 'cpu' => 50.0, 'memUsed' => 900, 'diskBytes' => 1, 'net' => 3.0],
    ];

    expect(array_column(FleetMetricsAggregator::rankContainers($containers, 'cpu', 2), 'id'))->toBe(['b', 'c']);
    expect(array_column(FleetMetricsAggregator::rankContainers($containers, 'memory'), 'id'))->toBe(['c', 'a', 'b']);
    expect(array_column(FleetMetricsAggregator::rankContainers($containers, 'network'), 'id'))->toBe(['c', 'b', 'a']);
});
