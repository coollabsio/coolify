<?php

use App\Data\Traffic\TrafficBreakdownData;
use App\Data\Traffic\TrafficOverviewData;
use App\Data\Traffic\TrafficPathData;

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

it('maps per-path error counters from sentinel', function () {
    $dto = TrafficPathData::fromSentinel([
        'path' => '/api/checkout',
        'app' => 'app-1',
        'requests' => 20,
        'bytes_out' => 1000,
        's4xx' => 3,
        's5xx' => 2,
        'p50' => 10,
        'p95' => 30,
    ]);

    expect($dto->s4xx)->toBe(3)
        ->and($dto->s5xx)->toBe(2);
});

it('maps city coordinates from sentinel', function () {
    $dto = TrafficBreakdownData::fromSentinel([
        'value' => 'DE|BE|Berlin', 'requests' => 9, 'bytes_out' => 900, 'lat' => 52.52, 'lon' => 13.405,
    ]);

    expect($dto->value)->toBe('DE|BE|Berlin')
        ->and($dto->lat)->toBe(52.52)
        ->and($dto->lon)->toBe(13.405);
});

it('leaves coordinates null when sentinel sends none or sends only one', function (array $row) {
    $dto = TrafficBreakdownData::fromSentinel(['value' => 'DE||', 'requests' => 2, 'bytes_out' => 20] + $row);

    expect($dto->lat)->toBeNull()->and($dto->lon)->toBeNull();
})->with([
    'none' => [[]],
    'only lat' => [['lat' => 52.52]],
    'not numeric' => [['lat' => 'x', 'lon' => 'y']],
    'not finite' => [['lat' => INF, 'lon' => 13.4]],
    'latitude out of range' => [['lat' => 91, 'lon' => 13.4]],
    'longitude out of range' => [['lat' => 52.5, 'lon' => -181]],
]);
