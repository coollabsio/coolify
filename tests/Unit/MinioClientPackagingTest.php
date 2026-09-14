<?php

it('pins the AIStor MinIO client release in every Coolify image', function () {
    $imageFiles = [
        dirname(__DIR__, 2).'/docker/production/Dockerfile',
        dirname(__DIR__, 2).'/docker/development/Dockerfile',
        dirname(__DIR__, 2).'/docker/coolify-helper/Dockerfile',
        dirname(__DIR__, 2).'/docker-compose.dev.yml',
        dirname(__DIR__, 2).'/docker-compose.dev-multi.yml',
        dirname(__DIR__, 2).'/docker-compose-maxio.dev.yml',
    ];

    foreach ($imageFiles as $imageFile) {
        $contents = file_get_contents($imageFile);

        expect($contents)
            ->toContain('quay.io/minio/aistor/mc:RELEASE.2026-09-06T02-44-40Z')
            ->not->toContain('quay.io/minio/aistor/mc:latest')
            ->not->toContain('minio/mc:');
    }
});
