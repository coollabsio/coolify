<?php

it('uses the mx MinIO-compatible client in development', function () {
    $developmentFiles = [
        dirname(__DIR__, 2).'/docker/development/Dockerfile',
        dirname(__DIR__, 2).'/docker-compose.dev.yml',
        dirname(__DIR__, 2).'/docker-compose.dev-multi.yml',
        dirname(__DIR__, 2).'/docker-compose-maxio.dev.yml',
    ];

    foreach ($developmentFiles as $developmentFile) {
        $contents = file_get_contents($developmentFile);

        expect($contents)
            ->toContain('ghcr.io/coollabsio/mx:0.1.0')
            ->not->toContain('quay.io/minio/aistor/mc:')
            ->not->toContain('minio/mc:');
    }
});

it('keeps the mc command path when packaging mx', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/development/Dockerfile');

    expect($dockerfile)
        ->toContain('COPY --from=minio-client /usr/bin/mc /usr/bin/mc')
        ->not->toContain('COPY --from=minio-client /usr/bin/mx');
});
