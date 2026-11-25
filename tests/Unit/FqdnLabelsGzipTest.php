<?php

/**
 * Traefik compression must not buffer Server-Sent Events (text/event-stream), otherwise
 * streaming endpoints such as the n8n MCP trigger never respond. It must not compress
 * video and audio either: compression removes `Accept-Ranges`, so Safari cannot play
 * the file and other browsers cannot seek (#5222).
 *
 * The middleware has its own name because running containers declare the older `gzip`
 * and `gzip-sse` middlewares: a different definition under the same name makes Traefik
 * drop it for all routers until every resource is redeployed.
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

test('gzip compression excludes event streams and media responses', function (string $domain, array $routers) {
    $labels = gzipTraefikLabels(true, $domain);

    $definition = collect($labels)->first(
        fn (string $label) => str_starts_with($label, 'traefik.http.middlewares.gzip-no-media.compress.excludedcontenttypes=')
    );
    expect($definition)->not->toBeNull();
    expect(explode(',', str($definition)->after('=')->toString()))
        ->toContain('text/event-stream', 'video/mp4', 'video/webm', 'video/quicktime', 'audio/mpeg', 'application/octet-stream')
        ->not->toContain('text/html', 'application/json', 'video/*');

    expect(collect($labels)->filter(fn (string $label) => str_contains($label, 'middlewares.gzip.') || str_contains($label, 'middlewares.gzip-sse.')))
        ->toBeEmpty();

    foreach ($routers as $router) {
        expect(middlewaresOfRouter($labels, $router))->toContain('gzip-no-media')->not->toContain('gzip', 'gzip-sse');
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
