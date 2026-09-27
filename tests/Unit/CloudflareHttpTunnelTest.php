<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Support\CloudflareHttpTunnel;
use App\Support\DomainUrlParts;
use Tests\TestCase;

uses(TestCase::class);

function httpTunnelServer(array $settings = []): Server
{
    $server = new Server(['ip' => '203.0.113.10', 'name' => 'homelab']);
    $server->id = 1;
    $server->setRelation('settings', new ServerSetting(array_merge([
        'is_cloudflare_http_tunnel' => true,
        'cloudflare_http_tunnel_cname' => 'abcd1234.cfargotunnel.com',
        'wildcard_domain' => 'https://example.com',
    ], $settings)));

    return $server;
}

it('recognizes cfargotunnel CNAME targets', function () {
    expect(CloudflareHttpTunnel::isCfargoTarget('Abcd1234.cfargotunnel.com.'))->toBeTrue()
        ->and(CloudflareHttpTunnel::isCfargoTarget('example.com'))->toBeFalse();
});

it('rewrites https resource URLs to http for tunneled origin labels', function () {
    $domains = CloudflareHttpTunnel::httpOriginDomains([
        'https://app.example.com',
        'http://api.example.com',
    ], httpTunnelServer());

    expect($domains->all())->toBe([
        'http://app.example.com',
        'http://api.example.com',
    ]);
});

it('does not rewrite domains when HTTP tunnel mode is off', function () {
    $server = httpTunnelServer(['is_cloudflare_http_tunnel' => false]);

    expect(CloudflareHttpTunnel::httpOriginDomains(['https://app.example.com'], $server)->all())
        ->toBe(['https://app.example.com']);
});

it('skips sslip.io generation for tunneled servers without a wildcard', function () {
    $server = httpTunnelServer(['wildcard_domain' => null]);

    expect(generateUrl($server, 'random'))->toBe('')
        ->and(generateFqdn($server, 'random'))->toBe('');
});

it('generates http preview URLs from a wildcard on a tunneled server', function () {
    expect(generateUrl(httpTunnelServer(), 'preview123'))->toBe('http://preview123.example.com');
});

it('defaults empty domain parts to http for tunnel mode helpers', function () {
    expect(DomainUrlParts::empty('http')['scheme'])->toBe('http')
        ->and(DomainUrlParts::empty()['scheme'])->toBe('https');
});

it('does not emit letsencrypt certresolver labels for http origin domains', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'testuuid',
        domains: collect(['http://app.example.com']),
        onlyPort: 3000,
        is_force_https_enabled: false,
    );

    expect($labels->implode("\n"))
        ->not->toContain('tls.certresolver=letsencrypt')
        ->toContain('entryPoints=http');
});
