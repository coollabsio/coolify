<?php

it('includes an Executor one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/executor.yaml';

    expect($templatePath)->toBeFile();

    $compose = file_get_contents($templatePath);

    expect($compose)
        ->toContain('ghcr.io/rhyssullivan/executor-selfhost:${EXECUTOR_VERSION:-latest}')
        ->toContain('SERVICE_URL_EXECUTOR_4788')
        ->toContain('EXECUTOR_WEB_BASE_URL=${SERVICE_URL_EXECUTOR_4788}')
        ->toContain('executor-data:/data')
        ->toContain('http://127.0.0.1:${process.env.PORT||4788}/api/health');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templateCatalogPath = __DIR__."/../../templates/{$templateFile}";
        $templateCatalog = file_get_contents($templateCatalogPath);

        if ($templateCatalog === false) {
            throw new RuntimeException("Unable to read service template catalog at {$templateCatalogPath}.");
        }

        $templates = json_decode(
            $templateCatalog,
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('executor');
        expect($templates['executor']['port'] ?? null)->toBe('4788');
        expect($templates['executor']['logo'] ?? null)->toBe('svgs/executor.png');
        expect($templates['executor']['category'] ?? null)->toBe('development');

        $generatedCompose = base64_decode($templates['executor']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('ghcr.io/rhyssullivan/executor-selfhost:${EXECUTOR_VERSION:-latest}')
            ->toContain('http://127.0.0.1:${process.env.PORT||4788}/api/health')
            ->toContain($templateFile === 'service-templates.json'
                ? 'EXECUTOR_WEB_BASE_URL=${SERVICE_FQDN_EXECUTOR_4788}'
                : 'EXECUTOR_WEB_BASE_URL=${SERVICE_URL_EXECUTOR_4788}');
    }
});

it('ships the Executor service icon from the public path used by the service picker', function () {
    expect(__DIR__.'/../../public/svgs/executor.png')->toBeFile();
});
