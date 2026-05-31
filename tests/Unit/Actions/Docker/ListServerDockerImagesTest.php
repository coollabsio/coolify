<?php

use App\Actions\Docker\ListServerDockerImages;

it('normalizes docker image output and marks images used by containers', function () {
    $images = ListServerDockerImages::normalizeImages(collect([
        [
            'Repository' => 'ghcr.io/coollabsio/coolify',
            'Tag' => 'latest',
            'Digest' => '<none>',
            'ID' => 'sha256:abc123',
            'CreatedSince' => '2 days ago',
            'Size' => '1.2GB',
        ],
        [
            'Repository' => 'redis',
            'Tag' => '7-alpine',
            'Digest' => '<none>',
            'ID' => 'sha256:def456',
            'CreatedSince' => '4 days ago',
            'Size' => '42MB',
        ],
    ]), collect([
        'ghcr.io/coollabsio/coolify:latest',
    ]));

    expect($images)->toHaveCount(2)
        ->and($images->firstWhere('id', 'sha256:abc123')['in_use'])->toBeTrue()
        ->and($images->firstWhere('id', 'sha256:def456')['in_use'])->toBeFalse();
});

it('shows which containers use an image', function () {
    $containers = collect([
        [
            'ID' => 'container123',
            'Names' => 'coolify',
            'Image' => 'ghcr.io/coollabsio/coolify:latest',
            'State' => 'running',
            'Status' => 'Up 3 minutes',
        ],
    ]);

    $images = ListServerDockerImages::normalizeImages(collect([
        [
            'Repository' => 'ghcr.io/coollabsio/coolify',
            'Tag' => 'latest',
            'ID' => 'sha256:abc123',
        ],
    ]), ListServerDockerImages::usedImageReferencesFromContainers($containers), $containers);

    expect($images->first()['containers'])->toMatchArray([
        [
            'id' => 'container123',
            'name' => 'coolify',
            'state' => 'running',
            'status' => 'Up 3 minutes',
        ],
    ]);
});

it('marks digest-referenced images as used', function () {
    $images = ListServerDockerImages::normalizeImages(collect([
        [
            'Repository' => 'registry.example.com/app',
            'Tag' => 'stable',
            'Digest' => 'sha256:feed',
            'ID' => 'sha256:123456',
        ],
    ]), collect([
        'registry.example.com/app@sha256:feed',
    ]));

    expect($images->first()['in_use'])->toBeTrue();
});

it('treats untagged images as dangling', function () {
    $images = ListServerDockerImages::normalizeImages(collect([
        [
            'Repository' => '<none>',
            'Tag' => '<none>',
            'ID' => 'sha256:untagged',
            'Size' => '18MB',
        ],
    ]), collect());

    expect($images->first())->toMatchArray([
        'dangling' => true,
        'reference' => null,
    ]);
});
