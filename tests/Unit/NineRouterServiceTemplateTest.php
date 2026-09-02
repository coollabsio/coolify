<?php

it('includes a 9router one-click service template', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/9router.yaml');

    expect($compose)
        ->toContain('decolua/9router:${9ROUTER_VERSION:-latest}')
        ->toContain('SERVICE_URL_9ROUTER_20128')
        ->toContain('DATA_DIR=/app/data')
        ->toContain('INITIAL_PASSWORD=${INITIAL_PASSWORD:?123456}')
        ->toContain('JWT_SECRET=${SERVICE_PASSWORDWITHSYMBOLS_64_9ROUTER-JWT-SECRET}')
        ->toContain('API_KEY_SECRET=${SERVICE_PASSWORDWITHSYMBOLS_9ROUTER-KEY-SECRET}')
        ->toContain('MACHINE_ID_SALT=${SERVICE_HEX_64_9ROUTER-HMAC-SALT}')
        ->toContain("'20128:20128/tcp'")
        ->toContain("'9router:/app/data'")
        ->toContain('http://127.0.0.1:20128/');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('9router');
        expect($templates['9router']['port'] ?? null)->toBe('20128');
        expect($templates['9router']['logo'] ?? null)->toBe('svgs/9router.svg');
        expect($templates['9router']['category'] ?? null)->toBe('ai');

        $generatedCompose = base64_decode($templates['9router']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('decolua/9router:${9ROUTER_VERSION:-latest}')
            ->toContain('JWT_SECRET=${SERVICE_PASSWORDWITHSYMBOLS_64_9ROUTER-JWT-SECRET}');
    }
});

it('ships the 9router service icon from the public path used by the service picker', function () {
    expect(__DIR__.'/../../public/svgs/9router.svg')->toBeFile();
});
