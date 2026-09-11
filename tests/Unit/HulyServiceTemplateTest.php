<?php

it('includes a production-ready Huly one-click service template', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/huly.yaml');

    expect($compose)
        ->toContain('nginx:1.27.4-alpine')
        ->toContain('SERVICE_URL_HULY')
        ->toContain('cockroachdb/cockroach:v24.3.4')
        ->toContain('docker.redpanda.com/redpandadata/redpanda:v24.3.6')
        ->toContain('minio/minio:RELEASE.2025-02-28T09-55-16Z')
        ->toContain('hardcoreeng/account:s0.7.437')
        ->toContain('hardcoreeng/front:s0.7.437')
        ->toContain('hardcoreeng/transactor:s0.7.437')
        ->toContain('hardcoreeng/collaborator:s0.7.437')
        ->toContain('huly-cr-data:')
        ->toContain('huly-files:');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('huly');
        expect($templates['huly']['port'] ?? null)->toBe('80');
        expect($templates['huly']['logo'] ?? null)->toBe('svgs/huly.svg');
        expect($templates['huly']['category'] ?? null)->toBe('productivity');

        $generatedCompose = base64_decode($templates['huly']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('cockroachdb/cockroach:v24.3.4')
            ->toContain('SERVICE_FQDN_HULY');
    }
});
