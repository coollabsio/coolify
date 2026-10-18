<?php

use App\Services\FleetMetricsAggregator;

function row(array $overrides = []): array
{
    return array_merge([
        'uuid' => 'u', 'name' => 'srv', 'online' => true,
        'cpu' => 10.0, 'memUsed' => 1_000, 'memTotal' => 10_000,
        'diskUsed' => 1_000, 'diskTotal' => 10_000, 'diskPercent' => 10.0,
        'load1' => 0.5, 'netRx' => 100.0, 'netTx' => 50.0, 'containers' => 2,
    ], $overrides);
}

it('weights memory and disk by totals, not mean of percents', function () {
    $rows = [
        row(['memUsed' => 100, 'memTotal' => 100, 'diskUsed' => 100, 'diskTotal' => 100, 'diskPercent' => 100.0]),
        row(['memUsed' => 10, 'memTotal' => 1_000, 'diskUsed' => 10, 'diskTotal' => 1_000, 'diskPercent' => 1.0]),
    ];

    $kpis = FleetMetricsAggregator::fleetKpis($rows);

    // Σused/Σtotal = 110/1100 = 10%, not the (100+1)/2 = 50.5% a naive mean gives.
    expect(round($kpis['memPercent'], 1))->toBe(10.0);
    expect(round($kpis['diskPercent'], 1))->toBe(10.0);
});

it('means cpu, flags approximate for multiple servers, and finds the busiest', function () {
    $kpis = FleetMetricsAggregator::fleetKpis([
        row(['name' => 'a', 'cpu' => 20.0]),
        row(['name' => 'b', 'cpu' => 80.0]),
    ]);

    expect($kpis['cpuAvg'])->toBe(50.0);
    expect($kpis['cpuApproximate'])->toBeTrue();
    expect($kpis['cpuBusiest'])->toBe(['name' => 'b', 'percent' => 80.0]);
});

it('sums network rates and reports busiest and average load', function () {
    $kpis = FleetMetricsAggregator::fleetKpis([
        row(['netRx' => 100.0, 'netTx' => 10.0, 'load1' => 0.5]),
        row(['netRx' => 200.0, 'netTx' => 20.0, 'load1' => 2.5]),
    ]);

    expect($kpis['netRx'])->toBe(300.0);
    expect($kpis['netTx'])->toBe(30.0);
    expect($kpis['loadBusiest'])->toBe(2.5);
    expect($kpis['loadAvg'])->toBe(1.5);
});

it('ignores offline rows and counts servers needing attention', function () {
    $kpis = FleetMetricsAggregator::fleetKpis([
        row(['cpu' => 95.0]),                 // over cpu error cutoff
        row(['online' => false, 'cpu' => 99.0]),
    ]);

    expect($kpis['serversOnline'])->toBe(1);
    expect($kpis['serversTotal'])->toBe(2);
    expect($kpis['needsAttention'])->toBe(1);
    expect($kpis['cpuAvg'])->toBe(95.0); // offline row excluded from the mean
});

it('returns zeroed kpis for no rows', function () {
    $kpis = FleetMetricsAggregator::fleetKpis([]);

    expect($kpis['serversOnline'])->toBe(0);
    expect($kpis['cpuAvg'])->toBe(0.0);
    expect($kpis['cpuBusiest'])->toBeNull();
});
