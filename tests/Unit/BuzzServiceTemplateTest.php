<?php

it('includes a production-ready Buzz one-click service template', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/buzz.yaml');

    expect($compose)
        ->toContain('ghcr.io/block/buzz:${BUZZ_TAG:-main}')
        ->toContain('SERVICE_URL_BUZZ_3000')
        ->toContain('RELAY_URL=wss://${SERVICE_FQDN_BUZZ}')
        ->toContain('BUZZ_RELAY_PRIVATE_KEY=${SERVICE_HEX_64_RELAYKEY}')
        ->toContain('BUZZ_AUTO_MIGRATE=${BUZZ_AUTO_MIGRATE:-true}')
        ->toContain('minio-init')
        ->toContain('exclude_from_hc: true')
        ->toContain('postgres:17-alpine')
        ->toContain('redis:7-alpine');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('buzz');
        expect($templates['buzz']['port'] ?? null)->toBe('3000');
        expect($templates['buzz']['logo'] ?? null)->toBe('svgs/buzz.svg');
        expect($templates['buzz']['category'] ?? null)->toBe('messaging');

        $generatedCompose = base64_decode($templates['buzz']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('ghcr.io/block/buzz:${BUZZ_TAG:-main}')
            ->toContain('wss://');
    }
});
