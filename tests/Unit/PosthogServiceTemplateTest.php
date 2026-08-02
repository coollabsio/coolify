<?php

use Symfony\Component\Yaml\Yaml;

it('publishes a PostHog one-click service template with representative services', function () {
    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('posthog');

        $encodedCompose = $templates['posthog']['compose'];
        expect($encodedCompose)->toBeString();

        $generatedCompose = base64_decode($encodedCompose, strict: true);
        expect($generatedCompose)->toBeString();

        $compose = Yaml::parse($generatedCompose);
        expect($compose['services'] ?? null)->toBeArray();

        foreach (['db', 'clickhouse', 'web', 'capture', 'replay-capture', 'feature-flags'] as $service) {
            expect($compose['services'])->toHaveKey($service);
        }
    }
});

it('publishes absolute FQDN URLs and a Content-Encoding-compatible SeaweedFS image', function () {
    $sourceTemplate = file_get_contents(__DIR__.'/../../templates/compose/posthog.yaml');

    expect($sourceTemplate)
        ->toContain('# fqdn_url_scheme: https')
        ->toContain('image: chrislusf/seaweedfs:4.29');

    $catalogExpectations = [
        'service-templates.json' => [
            'urls' => [
                'SITE_URL=https://${SERVICE_FQDN_POSTHOG}',
                'OBJECT_STORAGE_PUBLIC_ENDPOINT=https://${SERVICE_FQDN_POSTHOG}',
                'LIVESTREAM_HOST=https://${SERVICE_FQDN_POSTHOG}/livestream',
            ],
            'forbiddenUrls' => [
                'SITE_URL=${SERVICE_URL_POSTHOG}',
                'OBJECT_STORAGE_PUBLIC_ENDPOINT=${SERVICE_URL_POSTHOG}',
                'LIVESTREAM_HOST=${SERVICE_URL_POSTHOG}/livestream',
            ],
        ],
        'service-templates-latest.json' => [
            'urls' => [
                'SITE_URL=${SERVICE_URL_POSTHOG}',
                'OBJECT_STORAGE_PUBLIC_ENDPOINT=${SERVICE_URL_POSTHOG}',
                'LIVESTREAM_HOST=${SERVICE_URL_POSTHOG}/livestream',
            ],
            'forbiddenUrls' => [
                'SITE_URL=https://${SERVICE_FQDN_POSTHOG}',
                'OBJECT_STORAGE_PUBLIC_ENDPOINT=https://${SERVICE_FQDN_POSTHOG}',
                'LIVESTREAM_HOST=https://${SERVICE_FQDN_POSTHOG}/livestream',
            ],
        ],
    ];

    foreach ($catalogExpectations as $templateFile => $expectations) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $generatedCompose = base64_decode($templates['posthog']['compose'], strict: true);
        $compose = Yaml::parse($generatedCompose);

        expect($generatedCompose)->toContain(...$expectations['urls']);
        expect($generatedCompose)->not->toContain(...$expectations['forbiddenUrls']);
        expect($compose['services']['seaweedfs']['image'])->toBe('chrislusf/seaweedfs:4.29');
    }
});
