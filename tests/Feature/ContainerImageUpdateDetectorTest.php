<?php

use App\Services\ContainerImageUpdateDetector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

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

it('keeps the highest patch tag when a floating minor tag also exists', function () {
    Http::fake([
        'https://hub.docker.com/v2/repositories/library/redis/tags*' => Http::response([
            'results' => [
                ['name' => '7.2', 'digest' => 'sha256:120'],
                ['name' => '7.2.1', 'digest' => 'sha256:121'],
                ['name' => '7.2.0', 'digest' => 'sha256:120'],
            ],
        ], 200),
    ]);

    $detector = new ContainerImageUpdateDetector;
    $update = $detector->detect('redis:7.2.0');

    expect($update)->not->toBeNull()
        ->and($update['target_reference'])->toBe('redis:7.2.1');
});

it('matches semantic updates by complete major minor segments only', function () {
    Http::fake([
        'https://hub.docker.com/v2/repositories/library/redis/tags*' => Http::response([
            'results' => [
                ['name' => '1.21.4', 'digest' => 'sha256:214'],
                ['name' => '1.20.0', 'digest' => 'sha256:200'],
                ['name' => '1.2.4', 'digest' => 'sha256:124'],
                ['name' => '1.2.3', 'digest' => 'sha256:123'],
            ],
        ], 200),
    ]);

    $detector = new ContainerImageUpdateDetector;
    $update = $detector->detect('redis:1.2.3');

    expect($update)->not->toBeNull()
        ->and($update['target_reference'])->toBe('redis:1.2.4');
});

it('supports ghcr organization owners when listing tags', function () {
    Http::fake([
        'https://api.github.com/users/exampleorg/packages/container/demo/versions*' => Http::response([], 404),
        'https://api.github.com/orgs/exampleorg/packages/container/demo/versions*' => Http::response([
            [
                'name' => 'sha256:121',
                'metadata' => [
                    'container' => [
                        'tags' => ['1.2.1'],
                    ],
                ],
            ],
            [
                'name' => 'sha256:120',
                'metadata' => [
                    'container' => [
                        'tags' => ['1.2.0'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $detector = new ContainerImageUpdateDetector;
    $update = $detector->detect('ghcr.io/exampleorg/demo:1.2.0');

    expect($update)->not->toBeNull()
        ->and($update['target_reference'])->toBe('ghcr.io/exampleorg/demo:1.2.1');
});

it('supports ghcr packages with nested package paths', function () {
    Http::fake([
        'https://api.github.com/users/example/packages/container/hatchet%2Fhatchet-dashboard/versions*' => Http::response([
            [
                'name' => 'sha256:121',
                'metadata' => [
                    'container' => [
                        'tags' => ['1.2.1'],
                    ],
                ],
            ],
            [
                'name' => 'sha256:120',
                'metadata' => [
                    'container' => [
                        'tags' => ['1.2.0'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $detector = new ContainerImageUpdateDetector;
    $update = $detector->detect('ghcr.io/example/hatchet/hatchet-dashboard:1.2.0');

    expect($update)->not->toBeNull()
        ->and($update['target_reference'])->toBe('ghcr.io/example/hatchet/hatchet-dashboard:1.2.1');
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
