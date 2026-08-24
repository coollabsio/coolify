<?php

use App\Data\Traffic\TrafficOverviewData;

it('maps a sentinel overview payload into a DTO', function () {
    $json = [
        'requests' => 10, 'bytes_in' => 100, 'bytes_out' => 200,
        'status' => ['s2xx' => 8, 's3xx' => 1, 's4xx' => 1, 's5xx' => 0],
        'latency' => ['p50' => 12.5, 'p95' => 40.0, 'p99' => 90.0],
        'unique_visitors' => 5,
    ];
    $dto = TrafficOverviewData::fromSentinel($json);
    expect($dto->requests)->toBe(10);
    expect($dto->s4xx)->toBe(1);
    expect($dto->latencyP95)->toBe(40.0);
    expect($dto->uniqueVisitors)->toBe(5);
});

it('produces a zeroed overview', function () {
    expect(TrafficOverviewData::zero()->requests)->toBe(0);
});
