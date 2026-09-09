<?php

it('includes a Jean Server one-click service template with all deployment environment variables', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/jean.yaml');

    expect($compose)
        ->toContain('ghcr.io/coollabsio/jean-server:${JEAN_VERSION:-latest}')
        ->toContain('SERVICE_URL_JEAN_3456')
        ->toContain('JEAN_HEADLESS=${JEAN_HEADLESS:-1}')
        ->toContain('JEAN_HOST=${JEAN_HOST:-0.0.0.0}')
        ->toContain('JEAN_PORT=${JEAN_PORT:-3456}')
        ->toContain('JEAN_TOKEN=${SERVICE_PASSWORD_64_JEAN}')
        ->toContain('JEAN_NO_TOKEN=${JEAN_NO_TOKEN:-0}')
        ->toContain('JEAN_ALLOW_UNSAFE_NO_TOKEN=${JEAN_ALLOW_UNSAFE_NO_TOKEN:-0}')
        ->toContain('JEAN_ALLOW_NATIVE_OPEN=${JEAN_ALLOW_NATIVE_OPEN:-0}')
        ->toContain('JEAN_ALLOWED_ORIGINS=${JEAN_ALLOWED_ORIGINS:-}')
        ->toContain('JEAN_DATA_DIR=/home/jean/.local/share/com.jean.desktop')
        ->toContain('jean-data:/home/jean/.local/share/com.jean.desktop')
        ->toContain('http://127.0.0.1:3456/readyz');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('jean');
        expect($templates['jean']['port'] ?? null)->toBe('3456');
        expect($templates['jean']['logo'] ?? null)->toBe('svgs/jean.png');
        expect($templates['jean']['category'] ?? null)->toBe('development');

        $generatedCompose = base64_decode($templates['jean']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('ghcr.io/coollabsio/jean-server:${JEAN_VERSION:-latest}')
            ->toContain('JEAN_TOKEN=${SERVICE_PASSWORD_64_JEAN}');
    }
});

it('ships the Jean service icon from the public path used by the service picker', function () {
    expect(__DIR__.'/../../public/svgs/jean.png')->toBeFile();
});
