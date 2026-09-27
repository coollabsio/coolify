<?php

use App\Actions\Server\ConfigureCloudflareHttpOrigin;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Services\Cloudflare\CloudflareTunnelApi;
use App\Support\CloudflareHttpTunnel;
use App\Support\DomainUrlParts;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function httpTunnelServer(array $settings = []): Server
{
    $server = new Server(['ip' => '203.0.113.10', 'name' => 'homelab']);
    $server->id = 1;
    $server->setRelation('settings', new ServerSetting(array_merge([
        'is_cloudflare_http_tunnel' => true,
        'cloudflare_http_tunnel_id' => 'abcd1234',
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

it('builds catch-all ingress to the Coolify proxy and optional dashboard port', function () {
    config(['app.port' => 8000]);
    $action = new ConfigureCloudflareHttpOrigin;
    $rules = $action->ingressRules('*.example.com', 'coolify.example.com');

    expect($rules)->toBe([
        ['hostname' => 'coolify.example.com', 'service' => 'http://127.0.0.1:8000'],
        ['hostname' => '*.example.com', 'service' => 'http://127.0.0.1:80'],
        ['hostname' => 'example.com', 'service' => 'http://127.0.0.1:80'],
        ['service' => 'http_status:404'],
    ]);
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

it('redacts Cloudflare JWT-shaped tokens from error copy', function () {
    expect(CloudflareHttpTunnel::redactSecrets('Invalid token eyJhbGciOi.secret-token extra'))
        ->not->toContain('eyJhbGciOi.secret-token')
        ->and(CloudflareHttpTunnel::redactSecrets('Invalid token eyJhbGciOi.secret-token extra'))
        ->toContain('[redacted]');
});

it('does not overwrite an existing DNS record that is not the tunnel CNAME', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone1/dns_records*' => Http::response([
            'success' => true,
            'result' => [[
                'type' => 'A',
                'content' => '203.0.113.10',
                'name' => 'app.example.com',
            ]],
        ]),
    ]);

    expect(fn () => app(CloudflareTunnelApi::class)->createProxiedCnameIfMissing(
        'token',
        'zone1',
        'app.example.com',
        'abcd1234.cfargotunnel.com',
    ))->toThrow(RuntimeException::class, 'Cloudflare will not overwrite the existing A record for app.example.com (203.0.113.10). Create a proxied CNAME to abcd1234.cfargotunnel.com only when the name is unused.');
});
