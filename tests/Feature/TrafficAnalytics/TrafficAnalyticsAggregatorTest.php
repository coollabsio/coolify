<?php

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
