<?php

use App\Traits\HasMetrics;

/**
 * Test double exposing the new metric mappers without SSH: overrides the fetch step
 * to return canned Sentinel rows.
 */
class MetricsMappingDouble
{
    use HasMetrics;

    /** @var array<string, ?array> type => rows */
    public array $rowsByType = [];

    protected function fetchMetricRows(string $type, int $mins): ?array
    {
        return $this->rowsByType[$type] ?? null;
    }
}

it('keeps only the root mount for disk metrics', function () {
    $d = new MetricsMappingDouble;
    $d->rowsByType['disk'] = [
        ['time' => 1000, 'mount' => '/boot', 'usedPercent' => 90.0],
        ['time' => 1000, 'mount' => '/', 'usedPercent' => 40.0],
        ['time' => 2000, 'mount' => '/', 'usedPercent' => 42.0],
    ];

    expect($d->getDiskMetrics(5))->toBe([[1000, 40.0], [2000, 42.0]]);
});

it('splits network metrics into rx and tx series', function () {
    $d = new MetricsMappingDouble;
    $d->rowsByType['network'] = [
        ['time' => 1000, 'rxBytesPerSec' => 100.0, 'txBytesPerSec' => 10.0],
        ['time' => 2000, 'rxBytesPerSec' => 200.0, 'txBytesPerSec' => 20.0],
    ];

    expect($d->getNetworkMetrics(5))->toBe([
        'rx' => [[1000, 100.0], [2000, 200.0]],
        'tx' => [[1000, 10.0], [2000, 20.0]],
    ]);
});

it('maps the one minute load average', function () {
    $d = new MetricsMappingDouble;
    $d->rowsByType['load'] = [
        ['time' => 1000, 'load1' => 0.5, 'load5' => 1.0, 'load15' => 1.5],
    ];

    expect($d->getLoadMetrics(5))->toBe([[1000, 0.5]]);
});

it('returns null for a metric when metrics are unavailable', function () {
    $d = new MetricsMappingDouble; // rowsByType empty -> fetch returns null

    expect($d->getDiskMetrics(5))->toBeNull();
    expect($d->getNetworkMetrics(5))->toBeNull();
    expect($d->getLoadMetrics(5))->toBeNull();
});
