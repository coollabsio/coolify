<?php

it('includes a DBX one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/dbx.yaml';

    expect($templatePath)->toBeFile();

    $compose = file_get_contents($templatePath);

    expect($compose)
        ->toContain('t8y2/dbx:${DBX_VERSION:-latest}')
        ->toContain('SERVICE_URL_DBX_4224')
        ->toContain('DBX_PASSWORD=${SERVICE_PASSWORD_DBX}')
        ->toContain('dbx-data:/app/data');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('dbx');
        expect($templates['dbx']['port'] ?? null)->toBe('4224');
        expect($templates['dbx']['logo'] ?? null)->toBe('svgs/dbx.png');
        expect($templates['dbx']['category'] ?? null)->toBe('database');

        $generatedCompose = base64_decode($templates['dbx']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('t8y2/dbx:${DBX_VERSION:-latest}')
            ->toContain('DBX_PASSWORD=${SERVICE_PASSWORD_DBX}')
            ->toContain($templateFile === 'service-templates.json'
                ? 'SERVICE_FQDN_DBX_4224'
                : 'SERVICE_URL_DBX_4224');
    }
});

it('ships the DBX service icon from the public path used by the service picker', function () {
    expect(__DIR__.'/../../public/svgs/dbx.png')->toBeFile();
});
