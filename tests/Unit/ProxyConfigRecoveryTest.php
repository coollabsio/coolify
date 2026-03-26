<?php

use App\Actions\Proxy\GetProxyConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\SchemalessAttributes\SchemalessAttributes;

beforeEach(function () {
    Log::spy();
    Cache::spy();
});

function mockServerWithDbConfig(?string $savedConfig, string $proxyType = 'TRAEFIK'): object
{
    $proxyAttributes = Mockery::mock(SchemalessAttributes::class);
    $proxyAttributes->shouldReceive('get')
        ->with('last_saved_proxy_configuration')
        ->andReturn($savedConfig);

    $proxyPath = match ($proxyType) {
        'CADDY' => '/data/coolify/proxy/caddy',
        'NGINX' => '/data/coolify/proxy/nginx',
        default => '/data/coolify/proxy/',
    };

    $server = Mockery::mock('App\Models\Server');
    $server->shouldIgnoreMissing();
    $server->shouldReceive('getAttribute')->with('proxy')->andReturn($proxyAttributes);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('name')->andReturn('Test Server');
    $server->shouldReceive('proxyType')->andReturn($proxyType);
    $server->shouldReceive('proxyPath')->andReturn($proxyPath);

    return $server;
}

it('returns OK for NONE proxy type without reading config', function () {
    $server = Mockery::mock('App\Models\Server');
    $server->shouldIgnoreMissing();
    $server->shouldReceive('proxyType')->andReturn('NONE');

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe('OK');
});

it('reads proxy configuration from database', function () {
    $savedConfig = "services:\n  traefik:\n    image: traefik:v3.5\n";
    $server = mockServerWithDbConfig($savedConfig);

    // ProxyDashboardCacheService is called at the end — mock it
    $server->shouldReceive('proxyType')->andReturn('TRAEFIK');

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($savedConfig);
});

it('preserves full custom config including labels, env vars, and custom commands', function () {
    $customConfig = <<<'YAML'
services:
  traefik:
    image: traefik:v3.5
    command:
      - '--entrypoints.http.address=:80'
      - '--metrics.prometheus=true'
    labels:
      - 'traefik.enable=true'
      - 'waf.custom.middleware=true'
    environment:
      CF_API_EMAIL: user@example.com
      CF_API_KEY: secret-key
YAML;

    $server = mockServerWithDbConfig($customConfig);

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($customConfig)
        ->and($result)->toContain('waf.custom.middleware=true')
        ->and($result)->toContain('CF_API_EMAIL')
        ->and($result)->toContain('metrics.prometheus=true');
});

it('logs warning when regenerating defaults', function () {
    Log::swap(new \Illuminate\Log\LogManager(app()));
    Log::spy();

    // No DB config, no disk config — will try to regenerate
    $server = mockServerWithDbConfig(null);

    // backfillFromDisk will be called — we need instant_remote_process to return empty
    // Since it's a global function we can't easily mock it, so test the logging via
    // the force regenerate path instead
    try {
        GetProxyConfiguration::run($server, forceRegenerate: true);
    } catch (\Throwable $e) {
        // generateDefaultProxyConfiguration may fail without full server setup
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'regenerated to defaults'))
        ->once();
});

it('does not read from disk when DB config exists', function () {
    $savedConfig = "services:\n  traefik:\n    image: traefik:v3.5\n";
    $server = mockServerWithDbConfig($savedConfig);

    // If disk were read, instant_remote_process would be called.
    // Since we're not mocking it and the test passes, it proves DB is used.
    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($savedConfig);
});

it('rejects stored Traefik config when proxy type is CADDY', function () {
    Log::swap(new \Illuminate\Log\LogManager(app()));
    Log::spy();

    $traefikConfig = "services:\n  traefik:\n    image: traefik:v3.6\n";
    $server = mockServerWithDbConfig($traefikConfig, 'CADDY');

    // Config type mismatch should trigger regeneration, which will try
    // backfillFromDisk (instant_remote_process) then generateDefault.
    // Both will fail in test env, but the warning log proves mismatch was detected.
    try {
        GetProxyConfiguration::run($server);
    } catch (\Throwable $e) {
        // Expected — regeneration requires SSH/full server setup
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'does not match current proxy type'))
        ->once();
});

it('rejects stored Caddy config when proxy type is TRAEFIK', function () {
    Log::swap(new \Illuminate\Log\LogManager(app()));
    Log::spy();

    $caddyConfig = "services:\n  caddy:\n    image: lucaslorentz/caddy-docker-proxy:2.8-alpine\n";
    $server = mockServerWithDbConfig($caddyConfig, 'TRAEFIK');

    try {
        GetProxyConfiguration::run($server);
    } catch (\Throwable $e) {
        // Expected — regeneration requires SSH/full server setup
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'does not match current proxy type'))
        ->once();
});

it('accepts stored Caddy config when proxy type is CADDY', function () {
    $caddyConfig = "services:\n  caddy:\n    image: lucaslorentz/caddy-docker-proxy:2.8-alpine\n";
    $server = mockServerWithDbConfig($caddyConfig, 'CADDY');

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($caddyConfig);
});

it('accepts stored config when YAML parsing fails', function () {
    $invalidYaml = "this: is: not: [valid yaml: {{{}}}";
    $server = mockServerWithDbConfig($invalidYaml, 'TRAEFIK');

    // Invalid YAML should not block — configMatchesProxyType returns true on parse failure
    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($invalidYaml);
});
