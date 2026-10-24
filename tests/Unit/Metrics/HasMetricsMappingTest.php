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

    public string $uuid = 'app-uuid';

    protected function fetchMetricRows(string $type, int $mins): ?array
    {
        return $this->rowsByType[$type] ?? null;
    }

    public function endpoint(string $type, int $mins): string
    {
        return $this->getMetricsEndpoint($type, '2026-01-01T00:00:00Z', $mins);
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

it('does not use non-root mounts when root disk samples are missing', function () {
    $double = new MetricsMappingDouble;
    $double->rowsByType['disk'] = [['time' => 1000, 'mount' => '/boot', 'usedPercent' => 90]];

    expect($double->getDiskMetrics(5))->toBe([]);
});

it('uses the Sentinel data mount for disk metrics when no root mount is reported', function () {
    $double = new MetricsMappingDouble;
    $double->rowsByType['disk'] = [
        ['time' => 1000, 'mount' => '/boot', 'usedPercent' => 90],
        ['time' => 1000, 'mount' => '/app/db', 'usedPercent' => 30],
    ];

    expect($double->getDiskMetrics(5))->toBe([[1000, 30.0]]);
});

it('supports older disk rows without a mount field', function () {
    $double = new MetricsMappingDouble;
    $double->rowsByType['disk'] = [['time' => 1000, 'usedPercent' => 40]];

    expect($double->getDiskMetrics(5))->toBe([[1000, 40.0]]);
});

it('asks sentinel to bucket ranges over an hour into at most 1000 whole-minute rows', function () {
    $double = new MetricsMappingDouble;

    expect($double->endpoint('cpu', 60))->not->toContain('bucket_ms')
        ->and($double->endpoint('cpu', 720))->toBe('http://localhost:8888/api/container/app-uuid/cpu/history?from=2026-01-01T00:00:00Z&bucket_ms=60000')
        ->and($double->endpoint('cpu', 10080))->toEndWith('&bucket_ms=660000')
        ->and($double->endpoint('cpu', 43200))->toEndWith('&bucket_ms=2640000');
});
