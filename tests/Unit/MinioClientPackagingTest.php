<?php

it('pins the same MinIO client release in every Coolify image', function () {
    $dockerfiles = [
        dirname(__DIR__, 2).'/docker/production/Dockerfile',
        dirname(__DIR__, 2).'/docker/development/Dockerfile',
        dirname(__DIR__, 2).'/docker/coolify-helper/Dockerfile',
    ];

    $versions = collect($dockerfiles)->map(function (string $dockerfile): string {
        $contents = file_get_contents($dockerfile);

        expect(preg_match('/^ARG MINIO_VERSION=(.+)$/m', $contents, $matches))->toBe(1);

        return $matches[1];
    });

    expect($versions->unique()->values()->all())
        ->toBe(['RELEASE.2025-08-13T08-35-41Z']);
});
