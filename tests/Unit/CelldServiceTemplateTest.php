<?php

it('includes a celld one-click service template', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/celld.yaml');

    expect($compose)
        ->toContain('ghcr.io/denoland/celld:${CELLD_VERSION:-latest}')
        ->toContain('SERVICE_URL_CELLD_8080')
        ->toContain('CELLD_BUCKET=${CELLD_BUCKET:?}')
        ->toContain('S3_ENDPOINT=${S3_ENDPOINT:-}')
        ->toContain('CELLD_ADDR=0.0.0.0:8080')
        ->toContain('CELLD_ADVERTISE=celld:8080')
        ->toContain('celld-data:/var/lib/celld');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('celld');
        expect($templates['celld']['port'] ?? null)->toBe('8080');
        expect($templates['celld']['logo'] ?? null)->toBe('svgs/denoKV.svg');
        expect($templates['celld']['category'] ?? null)->toBe('backend');

        $generatedCompose = base64_decode($templates['celld']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('ghcr.io/denoland/celld:${CELLD_VERSION:-latest}')
            ->toContain('CELLD_BUCKET=${CELLD_BUCKET:?}');
    }
});
