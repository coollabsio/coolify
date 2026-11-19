<?php

use App\Data\Traffic\TrafficBreakdownData;
use App\Data\Traffic\TrafficOverviewData;
use App\Services\TrafficAnalyticsAggregator;

it('sums additive fields and flags latency/uniques as approximate', function () {
    $a = new TrafficOverviewData(10, 1, 2, 8, 1, 1, 0, 5.0, 20.0, 30.0, 4);
    $b = new TrafficOverviewData(20, 3, 4, 18, 1, 1, 0, 9.0, 50.0, 60.0, 6);

    $result = TrafficAnalyticsAggregator::sumOverviews([$a, $b]);
    $o = $result['overview'];
    expect($o->requests)->toBe(30);
    expect($o->bytesOut)->toBe(6);
    expect($o->s2xx)->toBe(26);
    expect($o->uniqueVisitors)->toBe(10);        // summed
    expect($o->latencyP95)->toBe(50.0);          // max across servers
    expect($result['latencyApproximate'])->toBeTrue();
    expect($result['uniquesApproximate'])->toBeTrue();
});

it('returns a zeroed, non-approximate result for a single server', function () {
    $a = new TrafficOverviewData(10, 1, 2, 8, 1, 1, 0, 5.0, 20.0, 30.0, 4);
    $result = TrafficAnalyticsAggregator::sumOverviews([$a]);
    expect($result['latencyApproximate'])->toBeFalse();
    expect($result['uniquesApproximate'])->toBeFalse();
});

it('sums one city from two servers and keeps the first coordinates', function () {
    $aggregator = new TrafficAnalyticsAggregator(['city']);
    $aggregator->addBreakdown('city', [new TrafficBreakdownData('DE|BE|Berlin', 5, 500)]);
    $aggregator->addBreakdown('city', [new TrafficBreakdownData('DE|BE|Berlin', 4, 400, 52.52, 13.405)]);
    $aggregator->addBreakdown('city', [new TrafficBreakdownData('DE|BE|Berlin', 1, 100, 1.0, 2.0)]);

    expect($aggregator->breakdowns()['city'])->toBe([
        ['value' => 'DE|BE|Berlin', 'requests' => 10, 'bytesOut' => 1000, 'lat' => 52.52, 'lon' => 13.405],
    ]);
});

it('adds no coordinate keys to rows that have none', function () {
    $aggregator = new TrafficAnalyticsAggregator(['country', 'city']);
    $aggregator->addBreakdown('country', [new TrafficBreakdownData('DE', 5, 500)]);
    $aggregator->addBreakdown('city', [new TrafficBreakdownData('DE||', 5, 500)]);

    expect($aggregator->breakdowns())->toBe([
        'country' => [['value' => 'DE', 'requests' => 5, 'bytesOut' => 500]],
        'city' => [['value' => 'DE||', 'requests' => 5, 'bytesOut' => 500]],
    ]);
});
