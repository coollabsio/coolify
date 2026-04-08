<?php

use App\Services\ContainerImageUpdateDetector;
use Illuminate\Support\Facades\Http;

it('detects a newer semantic image tag on docker hub', function () {
    Http::fake([
        'https://hub.docker.com/v2/repositories/library/redis/tags*' => Http::response([
            'results' => [
                ['name' => '7.2.1', 'digest' => 'sha256:111'],
                ['name' => '7.2.0', 'digest' => 'sha256:000'],
            ],
        ], 200),
    ]);

    $detector = new ContainerImageUpdateDetector;
    $update = $detector->detect('redis:7.2.0');

    expect($update)->not->toBeNull()
        ->and($update['target_reference'])->toBe('redis:7.2.1');
});

it('does not invent an update for latest without a newer digest comparison', function () {
    Http::fake([
        'https://hub.docker.com/v2/repositories/library/redis/tags*' => Http::response([
            'results' => [
                ['name' => 'latest', 'digest' => 'sha256:111'],
                ['name' => '7.2.1', 'digest' => 'sha256:111'],
            ],
        ], 200),
    ]);

    $detector = new ContainerImageUpdateDetector;

    expect($detector->detect('redis:latest'))->toBeNull();
});
