<?php

it('includes a Temporal one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/temporal.yaml';

    expect($templatePath)->toBeFile();

    $compose = file_get_contents($templatePath);

    expect($compose)
        ->toContain('postgres:16-alpine')
        ->toContain('temporalio/auto-setup:${TEMPORAL_VERSION:-latest}')
        ->toContain('temporalio/admin-tools:${TEMPORAL_VERSION:-latest}')
        ->toContain('temporalio/ui:${TEMPORAL_UI_VERSION:-latest}')
        ->toContain('SERVICE_URL_TEMPORAL_UI_8080')
        ->toContain('DB=postgres12')
        ->toContain('POSTGRES_SEEDS=postgresql')
        ->toContain('TEMPORAL_ADDRESS=temporal:7233')
        ->toContain('temporal-postgresql-data:/var/lib/postgresql/data');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('temporal');
        expect($templates['temporal']['port'] ?? null)->toBe('8080');
        expect($templates['temporal']['category'] ?? null)->toBe('developer-tools');

        $generatedCompose = base64_decode($templates['temporal']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('temporalio/auto-setup:${TEMPORAL_VERSION:-latest}')
            ->toContain($templateFile === 'service-templates.json'
                ? 'SERVICE_FQDN_TEMPORAL_UI_8080'
                : 'SERVICE_URL_TEMPORAL_UI_8080');
    }
});
