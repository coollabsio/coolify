<?php

it('protects both routers with basic auth when force https is disabled', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'test-app',
        domains: collect(['https://example.com']),
        is_force_https_enabled: false,
        is_http_basic_auth_enabled: true,
        http_basic_auth_username: 'coolify',
        http_basic_auth_password: 'secret',
    );

    expect($labels)
        ->toContain('traefik.http.routers.https-0-test-app.middlewares=gzip,http-basic-auth-test-app')
        ->toContain('traefik.http.routers.http-0-test-app.middlewares=http-basic-auth-test-app');
});

it('keeps the http router redirect only when force https is enabled', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'test-app',
        domains: collect(['https://example.com']),
        is_force_https_enabled: true,
        is_http_basic_auth_enabled: true,
        http_basic_auth_username: 'coolify',
        http_basic_auth_password: 'secret',
    );

    expect($labels)
        ->toContain('traefik.http.routers.https-0-test-app.middlewares=gzip,http-basic-auth-test-app')
        ->toContain('traefik.http.routers.http-0-test-app.middlewares=redirect-to-https')
        ->not->toContain('traefik.http.routers.http-0-test-app.middlewares=redirect-to-https,http-basic-auth-test-app');
});
