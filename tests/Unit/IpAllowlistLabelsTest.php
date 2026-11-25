<?php

use App\Rules\ValidIpAllowlist;

/**
 * A per-application IP allowlist restricts proxy access to the given IPs and CIDR ranges (#7950).
 */
function ipAllowlistTraefikLabels(?string $allowlist, string $domain = 'https://example.com', bool $forceHttps = true): array
{
    return fqdnLabelsForTraefik(
        uuid: 'testuuid',
        domains: collect([$domain]),
        is_force_https_enabled: $forceHttps,
        onlyPort: 3000,
        is_gzip_enabled: false,
        ip_allowlist: $allowlist,
    )->values()->all();
}

/**
 * @return list<string>
 */
function ipAllowlistRuleErrors(string $value): array
{
    $errors = [];
    (new ValidIpAllowlist)->validate('ip_allowlist', $value, function (string $message) use (&$errors) {
        $errors[] = $message;
    });

    return $errors;
}

function ipAllowlistCaddyLabels(?string $allowlist, string $domain = 'https://example.com'): array
{
    return fqdnLabelsForCaddy(
        network: 'testnetwork',
        uuid: 'testuuid',
        domains: collect([$domain]),
        onlyPort: 3000,
        ip_allowlist: $allowlist,
    )->values()->all();
}

test('Traefik adds no allowlist middleware when the allowlist is empty', function (?string $allowlist) {
    expect(implode("\n", ipAllowlistTraefikLabels($allowlist)))->not->toContain('ipallowlist');
})->with([null, '', ' , ']);

test('Traefik puts the allowlist first on every router of the domain', function () {
    $labels = ipAllowlistTraefikLabels('203.0.113.5,10.0.0.0/8,2001:db8::/32');

    expect($labels)
        ->toContain('traefik.http.middlewares.ip-allowlist-testuuid.ipallowlist.sourcerange=203.0.113.5,10.0.0.0/8,2001:db8::/32')
        ->toContain('traefik.http.routers.https-0-testuuid.middlewares=ip-allowlist-testuuid')
        ->toContain('traefik.http.routers.http-0-testuuid.middlewares=ip-allowlist-testuuid,redirect-to-https');
});

test('Traefik applies the allowlist to plain http routers with a path prefix', function () {
    expect(ipAllowlistTraefikLabels('203.0.113.5', 'http://example.com/app', forceHttps: false))
        ->toContain('traefik.http.routers.http-0-testuuid.middlewares=ip-allowlist-testuuid,http-0-testuuid-stripprefix');
});

test('Caddy responds 403 to clients outside the allowlist before the reverse proxy', function () {
    $labels = ipAllowlistCaddyLabels('203.0.113.5,2001:db8::/32');

    expect($labels)
        ->toContain('caddy_0.@ip_not_allowed=not remote_ip 203.0.113.5 2001:db8::/32')
        ->toContain('caddy_0.handle_path.0_respond=@ip_not_allowed 403');
});

test('Caddy adds no allowlist labels when the allowlist is empty', function () {
    expect(implode("\n", ipAllowlistCaddyLabels(null)))->not->toContain('remote_ip');
});

test('invalid allowlist entries never reach the proxy labels', function () {
    $traefik = implode("\n", ipAllowlistTraefikLabels("203.0.113.5,evil\ntraefik.enable=false,10.0.0.0/33"));
    $caddy = implode("\n", ipAllowlistCaddyLabels("203.0.113.5,evil\ncaddy_0.respond=x"));

    expect($traefik)->toContain('ipallowlist.sourcerange=203.0.113.5')
        ->not->toContain('evil')
        ->not->toContain('10.0.0.0/33')
        ->and($caddy)->toContain('not remote_ip 203.0.113.5')
        ->not->toContain('evil');
});

test('the allowlist rule accepts IPv4, IPv6 and CIDR entries', function (string $value) {
    expect(ipAllowlistRuleErrors($value))->toBe([]);
})->with([
    '203.0.113.5',
    '10.0.0.0/8, 192.168.1.0/24',
    "2001:db8::1\n2001:db8::/32",
    '0.0.0.0/0',
]);

test('the allowlist rule rejects invalid entries and names them', function (string $value, string $invalidEntry) {
    expect(ipAllowlistRuleErrors($value))->toHaveCount(1)
        ->and(ipAllowlistRuleErrors($value)[0])->toContain($invalidEntry);
})->with([
    ['300.1.1.1', '300.1.1.1'],
    ['10.0.0.0/33', '10.0.0.0/33'],
    ['2001:db8::/129', '2001:db8::/129'],
    ['10.0.0.0/', '10.0.0.0/'],
    ['1.2.3.4/abc', '1.2.3.4/abc'],
    ['0.0.0.0foo', '0.0.0.0foo'],
    ['example.com', 'example.com'],
]);
