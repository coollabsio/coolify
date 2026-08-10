<?php

/**
 * Per-domain noindex labels.
 *
 * The X-Robots-Tag header must land on the router of the flagged domain only,
 * leaving every other domain of the same resource indexable.
 */
function traefikLabels(array $domains, ?array $noindex = null, bool $forceHttps = false): array
{
    return fqdnLabelsForTraefik(
        uuid: 'testuuid',
        domains: collect($domains),
        onlyPort: 3000,
        is_force_https_enabled: $forceHttps,
        noindex_domains: $noindex === null ? null : collect($noindex),
    )->values()->all();
}

function caddyLabels(array $domains, ?array $noindex = null): array
{
    return fqdnLabelsForCaddy(
        network: 'testnetwork',
        uuid: 'testuuid',
        domains: collect($domains),
        onlyPort: 3000,
        noindex_domains: $noindex === null ? null : collect($noindex),
    )->values()->all();
}

/** The middleware chain Traefik will apply to a given router. */
function middlewaresOf(array $labels, string $router): array
{
    $chain = collect($labels)->first(
        fn (string $label) => str_starts_with($label, "traefik.http.routers.{$router}.middlewares=")
    );

    if ($chain === null) {
        return [];
    }

    return explode(',', str($chain)->after('.middlewares=')->toString());
}

describe('Traefik noindex middleware', function () {
    test('only the flagged domain gets the header', function () {
        $labels = traefikLabels(
            domains: ['https://prod.example.com', 'https://staging.example.com'],
            noindex: ['https://staging.example.com'],
        );

        // The middleware is defined exactly once, for the second domain (loop index 1).
        expect($labels)->toContain(
            'traefik.http.middlewares.1-testuuid-noindex.headers.customresponseheaders.X-Robots-Tag=noindex, nofollow'
        );
        expect(collect($labels)->filter(fn ($l) => str_contains($l, 'X-Robots-Tag'))->count())->toBe(1);

        // ...and only the flagged domain's router references it.
        expect(middlewaresOf($labels, 'https-1-testuuid'))->toContain('1-testuuid-noindex');
        expect(middlewaresOf($labels, 'https-0-testuuid'))->not->toContain('1-testuuid-noindex');
    });

    test('no flags means byte-identical output to before the feature', function () {
        $domains = ['https://example.com', 'http://other.example.com/api'];

        expect(traefikLabels($domains, noindex: null))
            ->toBe(traefikLabels($domains, noindex: []));

        expect(collect(traefikLabels($domains, noindex: null))->filter(
            fn ($l) => str_contains($l, 'noindex')
        )->all())->toBeEmpty();
    });

    test('the header is applied on a subpath route', function () {
        $labels = traefikLabels(
            domains: ['https://example.com/admin'],
            noindex: ['https://example.com/admin'],
        );

        expect(middlewaresOf($labels, 'https-0-testuuid'))->toContain('0-testuuid-noindex');
    });

    test('the header is applied on a plain http route', function () {
        $labels = traefikLabels(
            domains: ['http://example.com'],
            noindex: ['http://example.com'],
        );

        expect(middlewaresOf($labels, 'http-0-testuuid'))->toContain('0-testuuid-noindex');
    });

    test('an https domain also gets the header on its http route', function () {
        $labels = traefikLabels(
            domains: ['https://example.com'],
            noindex: ['https://example.com'],
        );

        expect(middlewaresOf($labels, 'http-0-testuuid'))->toContain('0-testuuid-noindex');
    });

    test('the header wraps the http to https redirect', function () {
        $labels = traefikLabels(
            domains: ['https://example.com'],
            noindex: ['https://example.com'],
            forceHttps: true,
        );

        expect(middlewaresOf($labels, 'http-0-testuuid'))
            ->toBe(['0-testuuid-noindex', 'redirect-to-https']);
    });

    test('the header is applied on an http subpath route', function () {
        $labels = traefikLabels(
            domains: ['http://example.com/api'],
            noindex: ['http://example.com/api'],
        );

        expect(middlewaresOf($labels, 'http-0-testuuid'))->toContain('0-testuuid-noindex');
    });

    test('a casing difference does not silently drop the header', function () {
        $labels = traefikLabels(
            domains: ['https://Example.COM'],
            noindex: ['https://example.com'],
        );

        expect(middlewaresOf($labels, 'https-0-testuuid'))->toContain('0-testuuid-noindex');
    });

    test('a domain that is not configured is ignored', function () {
        $labels = traefikLabels(
            domains: ['https://example.com'],
            noindex: ['https://somewhere-else.com'],
        );

        expect(collect($labels)->filter(fn ($l) => str_contains($l, 'noindex'))->all())->toBeEmpty();
    });
});

describe('Caddy noindex header', function () {
    test('the flagged domain uses the header block, the other keeps the inline form', function () {
        $labels = caddyLabels(
            domains: ['https://prod.example.com', 'https://staging.example.com'],
            noindex: ['https://staging.example.com'],
        );

        // Caddy's header directive takes either inline args or a block, never both,
        // so the flagged domain has to move -Server into the block alongside the tag.
        expect($labels)->toContain('caddy_1.header.0_-Server=');
        expect($labels)->toContain('caddy_1.header.1_X-Robots-Tag="noindex, nofollow"');
        expect($labels)->not->toContain('caddy_1.header=-Server');

        // The unflagged domain is untouched.
        expect($labels)->toContain('caddy_0.header=-Server');
        expect(collect($labels)->filter(fn ($l) => str_contains($l, 'caddy_0.header.'))->all())->toBeEmpty();
    });

    test('no flags means byte-identical output to before the feature', function () {
        $domains = ['https://example.com', 'https://other.example.com'];

        expect(caddyLabels($domains, noindex: null))
            ->toBe(caddyLabels($domains, noindex: []));

        expect(collect(caddyLabels($domains, noindex: null))->filter(
            fn ($l) => str_contains($l, 'X-Robots-Tag')
        )->all())->toBeEmpty();
    });
});
