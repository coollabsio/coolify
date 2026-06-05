<?php

use App\Models\StandaloneDocker;

it('emits default http/https entryPoints when no entrypoint_suffix is provided', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'app-uuid',
        domains: collect(['https://example.com']),
    );

    expect($labels->contains('traefik.http.routers.https-0-app-uuid.entryPoints=https'))->toBeTrue();
    expect($labels->contains('traefik.http.routers.http-0-app-uuid.entryPoints=http'))->toBeTrue();
});

it('emits suffixed http/https entryPoints when entrypoint_suffix is provided', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'app-uuid',
        domains: collect(['https://example.com']),
        entrypoint_suffix: 'dest5',
    );

    expect($labels->contains('traefik.http.routers.https-0-app-uuid.entryPoints=https-dest5'))->toBeTrue();
    expect($labels->contains('traefik.http.routers.http-0-app-uuid.entryPoints=http-dest5'))->toBeTrue();
    expect($labels->contains('traefik.http.routers.https-0-app-uuid.entryPoints=https'))->toBeFalse();
    expect($labels->contains('traefik.http.routers.http-0-app-uuid.entryPoints=http'))->toBeFalse();
});

it('emits suffixed http entryPoint for plain http domain', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'app-uuid',
        domains: collect(['http://internal.lan']),
        entrypoint_suffix: 'dest2',
    );

    expect($labels->contains('traefik.http.routers.http-0-app-uuid.entryPoints=http-dest2'))->toBeTrue();
    expect($labels->contains('traefik.http.routers.http-0-app-uuid.entryPoints=http'))->toBeFalse();
});

it('treats blank entrypoint_suffix as default entryPoints', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'app-uuid',
        domains: collect(['https://example.com']),
        entrypoint_suffix: '',
    );

    expect($labels->contains('traefik.http.routers.https-0-app-uuid.entryPoints=https'))->toBeTrue();
    expect($labels->contains('traefik.http.routers.http-0-app-uuid.entryPoints=http'))->toBeTrue();
});

it('derives the suffix helper from a StandaloneDocker with bind_ip', function () {
    $bound = new StandaloneDocker;
    $bound->id = 5;
    $bound->bind_ip = '192.168.1.10';

    $unbound = new StandaloneDocker;
    $unbound->id = 6;
    $unbound->bind_ip = null;

    expect(getTraefikEntrypointSuffixForDestination($bound))->toBe('dest5');
    expect(getTraefikEntrypointSuffixForDestination($unbound))->toBeNull();
    expect(getTraefikEntrypointSuffixForDestination(null))->toBeNull();
});
