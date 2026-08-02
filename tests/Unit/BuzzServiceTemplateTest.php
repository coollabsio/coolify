<?php

it('includes a production-ready Buzz one-click service template', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/buzz.yaml');

    expect($compose)
        ->toContain('# fqdn_url_scheme: https')
        ->toContain('ghcr.io/block/buzz:${BUZZ_TAG:-main}')
        ->toContain('SERVICE_URL_BUZZ_3000')
        ->toContain('RELAY_URL=wss://${SERVICE_FQDN_BUZZ}')
        ->toContain('BUZZ_MEDIA_BASE_URL=${SERVICE_URL_BUZZ}/media')
        ->toContain('BUZZ_CORS_ORIGINS=${SERVICE_URL_BUZZ}')
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

        if ($templateFile === 'service-templates.json') {
            expect($generatedCompose)
                ->toContain('BUZZ_MEDIA_BASE_URL=https://${SERVICE_FQDN_BUZZ}/media')
                ->toContain('BUZZ_CORS_ORIGINS=https://${SERVICE_FQDN_BUZZ}');
        } else {
            expect($generatedCompose)
                ->toContain('BUZZ_MEDIA_BASE_URL=${SERVICE_URL_BUZZ}/media')
                ->toContain('BUZZ_CORS_ORIGINS=${SERVICE_URL_BUZZ}')
                ->not->toContain('BUZZ_MEDIA_BASE_URL=https://${SERVICE_FQDN_BUZZ}/media')
                ->not->toContain('BUZZ_CORS_ORIGINS=https://${SERVICE_FQDN_BUZZ}');
        }
    }

    $fqdnTemplates = json_decode(
        file_get_contents(__DIR__.'/../../templates/service-templates.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $chaskiqCompose = base64_decode($fqdnTemplates['chaskiq']['compose'], strict: true);

    expect($chaskiqCompose)
        ->toContain('HOST=${SERVICE_FQDN_CHASKIQ_3000}')
        ->not->toContain('HOST=https://${SERVICE_FQDN_CHASKIQ_3000}');
});
