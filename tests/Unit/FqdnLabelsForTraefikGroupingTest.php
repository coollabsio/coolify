<?php

it('groups traefik router rules for domains with matching routing options', function () {
    $domains = collect([
        'https://example.com',
        'https://example.org',
        'https://example.net',
    ]);

    $labels = fqdnLabelsForTraefik(
        uuid: 'app123',
        domains: $domains,
        is_force_https_enabled: true,
        onlyPort: 3000,
    );

    $httpsRules = $labels->filter(fn ($label) => str($label)->startsWith('traefik.http.routers.https-') && str($label)->contains('.rule='));
    $httpRules = $labels->filter(fn ($label) => str($label)->startsWith('traefik.http.routers.http-') && str($label)->contains('.rule='));

    expect($httpsRules)->toHaveCount(1)
        ->and($httpRules)->toHaveCount(1)
        ->and($httpsRules->first())->toContain('Host(`example.com`) || Host(`example.org`) || Host(`example.net`)')
        ->and($httpsRules->first())->toContain('PathPrefix(`/`)')
        ->and($labels->filter(fn ($label) => str($label)->contains('loadbalancer.server.port=3000')))->toHaveCount(1);
});

it('keeps separate traefik groups when domains need different redirect middleware', function () {
    $domains = collect([
        'https://example.com',
        'https://www.example.com',
        'https://example.org',
        'https://www.example.org',
    ]);

    $labels = fqdnLabelsForTraefik(
        uuid: 'app123',
        domains: $domains,
        is_force_https_enabled: true,
        onlyPort: 3000,
        redirect_direction: 'non-www',
    );

    $httpsRules = $labels->filter(fn ($label) => str($label)->startsWith('traefik.http.routers.https-') && str($label)->contains('.rule='));
    $redirectMiddlewares = $labels->filter(fn ($label) => str($label)->contains('redirectregex.regex=^(http|https)://www\.'));

    expect($httpsRules)->toHaveCount(2)
        ->and($redirectMiddlewares)->toHaveCount(1)
        ->and($labels->filter(fn ($label) => str($label)->contains('to-non-www')))->not->toBeEmpty();
});

