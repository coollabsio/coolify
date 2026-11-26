<?php

/**
 * Proxy redirects stay temporary unless the resource opts in to permanent redirects (#6032).
 */
function permanentRedirectTraefikLabels(bool $permanent): array
{
    return fqdnLabelsForTraefik(
        uuid: 'testuuid',
        domains: collect(['https://example.com', 'https://www.example.com']),
        is_force_https_enabled: true,
        onlyPort: 3000,
        redirect_direction: 'www',
        is_permanent_redirect_enabled: $permanent,
    )->values()->all();
}

function permanentRedirectCaddyLabels(bool $permanent): array
{
    return fqdnLabelsForCaddy(
        network: 'testnetwork',
        uuid: 'testuuid',
        domains: collect(['https://example.com', 'https://www.example.com']),
        is_force_https_enabled: true,
        onlyPort: 3000,
        redirect_direction: 'www',
        is_permanent_redirect_enabled: $permanent,
    )->values()->all();
}

test('Traefik redirects are temporary by default', function () {
    $labels = permanentRedirectTraefikLabels(false);

    expect($labels)
        ->toContain('traefik.http.middlewares.0-testuuid-to-www.redirectregex.permanent=false')
        ->toContain('traefik.http.routers.http-0-testuuid.middlewares=redirect-to-https')
        ->not->toContain('traefik.http.middlewares.redirect-to-https-permanent.redirectscheme.permanent=true');
});

test('Traefik redirects are permanent when enabled and keep the shared HTTPS middleware unchanged', function () {
    $labels = permanentRedirectTraefikLabels(true);

    expect($labels)
        ->toContain('traefik.http.middlewares.0-testuuid-to-www.redirectregex.permanent=true')
        ->toContain('traefik.http.middlewares.redirect-to-https-permanent.redirectscheme.scheme=https')
        ->toContain('traefik.http.middlewares.redirect-to-https-permanent.redirectscheme.permanent=true')
        ->toContain('traefik.http.routers.http-0-testuuid.middlewares=redirect-to-https-permanent')
        ->toContain('traefik.http.middlewares.redirect-to-https.redirectscheme.scheme=https')
        ->not->toContain('traefik.http.middlewares.redirect-to-https.redirectscheme.permanent=true');
});

test('Caddy www redirect uses the default temporary code unless permanent is enabled', function () {
    expect(permanentRedirectCaddyLabels(false))->toContain('caddy_0.redir=https://www.example.com{uri}')
        ->and(permanentRedirectCaddyLabels(true))->toContain('caddy_0.redir=https://www.example.com{uri} permanent');
});
