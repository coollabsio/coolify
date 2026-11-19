<?php

/**
 * Traefik compression must not buffer Server-Sent Events (text/event-stream),
 * otherwise streaming endpoints such as the n8n MCP trigger never respond.
 *
 * The middleware has its own name because every running container declares the
 * legacy `gzip` middleware: a different definition under the same name makes
 * Traefik drop it for all routers until every resource is redeployed.
 */
function gzipTraefikLabels(bool $gzip, string $domain = 'https://example.com'): array
{
    return fqdnLabelsForTraefik(
        uuid: 'testuuid',
        domains: collect([$domain]),
        onlyPort: 3000,
        is_gzip_enabled: $gzip,
    )->values()->all();
}

test('gzip compression excludes text/event-stream responses', function (string $domain, array $routers) {
    $labels = gzipTraefikLabels(true, $domain);

    expect($labels)
        ->toContain('traefik.http.middlewares.gzip-sse.compress.excludedcontenttypes=text/event-stream')
        ->not->toContain('traefik.http.middlewares.gzip.compress=true');

    foreach ($routers as $router) {
        expect(middlewaresOfRouter($labels, $router))->toContain('gzip-sse')->not->toContain('gzip');
    }
})->with([
    'https domain' => ['https://example.com', ['https-0-testuuid']],
    'https subpath' => ['https://example.com/api', ['https-0-testuuid']],
    'http domain' => ['http://example.com', ['http-0-testuuid']],
    'http subpath' => ['http://example.com/api', ['http-0-testuuid']],
]);

test('disabled gzip compression adds no compress middleware', function () {
    $labels = gzipTraefikLabels(false);

    expect(collect($labels)->filter(fn (string $label) => str_contains($label, 'gzip')))->toBeEmpty();
});

function middlewaresOfRouter(array $labels, string $router): array
{
    $chain = collect($labels)->first(
        fn (string $label) => str_starts_with($label, "traefik.http.routers.{$router}.middlewares=")
    );

    return $chain === null ? [] : explode(',', str($chain)->after('.middlewares=')->toString());
}
