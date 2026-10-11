<?php

/**
 * Traefik matches the Host header of an IPv6 request against the address without brackets:
 * Host(`[2a01:4f8::1]`) gives 404, Host(`2a01:4f8::1`) matches. Caddy needs the brackets.
 */
function traefikRuleLabels(array $domains, bool $forceHttps = false): array
{
    return fqdnLabelsForTraefik(
        uuid: 'testuuid',
        domains: collect($domains),
        is_force_https_enabled: $forceHttps,
        onlyPort: 3000,
    )->filter(fn (string $label) => str_contains($label, '.rule='))->values()->all();
}

it('writes IPv6 hosts without brackets in Traefik Host rules', function (string $domain, string $expectedRule) {
    $rules = traefikRuleLabels([$domain]);

    expect($rules)->not->toBeEmpty();
    foreach ($rules as $rule) {
        expect($rule)->toContain($expectedRule)->not->toContain('[');
    }
})->with([
    'http' => ['http://[2a01:4f8:c016:bd23::1]', 'Host(`2a01:4f8:c016:bd23::1`) && PathPrefix(`/`)'],
    'https' => ['https://[2a01:4f8:c016:bd23::1]', 'Host(`2a01:4f8:c016:bd23::1`) && PathPrefix(`/`)'],
    'with port and path' => ['http://[2a01:4f8::1]:8080/api', 'Host(`2a01:4f8::1`) && PathPrefix(`/api`)'],
]);

it('writes IPv6 hosts without brackets when https is forced', function () {
    $rules = traefikRuleLabels(['https://[2a01:4f8::1]'], forceHttps: true);

    expect($rules)->toHaveCount(2);
    foreach ($rules as $rule) {
        expect($rule)->toContain('Host(`2a01:4f8::1`)');
    }
});

it('keeps IPv4 and hostname Traefik Host rules unchanged', function (string $domain, string $host) {
    foreach (traefikRuleLabels([$domain]) as $rule) {
        expect($rule)->toContain("Host(`{$host}`) && PathPrefix(`/`)");
    }
})->with([
    'hostname' => ['https://app.example.com', 'app.example.com'],
    'IPv4' => ['http://192.0.2.10', '192.0.2.10'],
    'sslip' => ['http://app.2a01-4f8--1.sslip.io', 'app.2a01-4f8--1.sslip.io'],
]);

it('keeps brackets for IPv6 hosts in Caddy labels', function () {
    $labels = fqdnLabelsForCaddy(
        network: 'testnetwork',
        uuid: 'testuuid',
        domains: collect(['http://[2a01:4f8::1]']),
        onlyPort: 3000,
    )->values()->all();

    expect($labels)->toContain('caddy_0=http://[2a01:4f8::1]');
});

it('removes IPv6 brackets from Traefik Host rules in saved labels', function () {
    $labels = collect([
        'traefik.http.routers.http-0-app.rule=Host(`[2a01:4f8:c016:bd23::1]`) && PathPrefix(`/`)',
        'traefik.http.routers.http-1-app.rule=Host(`app.example.com`) && PathPrefix(`/`)',
        'traefik.http.routers.http-2-app.rule=Host(`188.245.12.182`)',
        'traefik.http.routers.custom.rule=Host(`[not-an-ip]`)',
        'caddy_0=http://[2a01:4f8:c016:bd23::1]',
    ]);

    expect(traefikHostRulesWithoutIpv6Brackets($labels)->all())->toBe([
        'traefik.http.routers.http-0-app.rule=Host(`2a01:4f8:c016:bd23::1`) && PathPrefix(`/`)',
        'traefik.http.routers.http-1-app.rule=Host(`app.example.com`) && PathPrefix(`/`)',
        'traefik.http.routers.http-2-app.rule=Host(`188.245.12.182`)',
        'traefik.http.routers.custom.rule=Host(`[not-an-ip]`)',
        'caddy_0=http://[2a01:4f8:c016:bd23::1]',
    ]);
});
