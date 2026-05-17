<?php

use App\Enums\ProxyTypes;
use Symfony\Component\Yaml\Yaml;

// Regen-safety contract: all --accesslog flags are stripped as managed defaults.
it('strips both --accesslog= and --accesslog.* so regeneration is idempotent', function () {
    $existingConfig = Yaml::dump([
        'services' => [
            'traefik' => [
                'command' => [
                    '--ping=true',
                    '--api.dashboard=true',
                    '--accesslog=true',
                    '--accesslog.filepath=/traefik/access.log',
                    '--accesslog.format=json',
                    '--accesslog.bufferingsize=100',
                    '--entrypoints.http.forwardedHeaders.trustedIPs=10.0.0.0/8',
                    '--providers.docker=true',
                ],
            ],
        ],
    ]);

    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);

    $customCommands = extractCustomProxyCommands($server, $existingConfig);

    expect($customCommands)
        ->toBeArray()
        ->toHaveCount(1)
        ->toContain('--entrypoints.http.forwardedHeaders.trustedIPs=10.0.0.0/8')
        ->not->toContain('--accesslog=true')
        ->not->toContain('--accesslog.filepath=/traefik/access.log')
        ->not->toContain('--accesslog.format=json')
        ->not->toContain('--accesslog.bufferingsize=100');
});

it('returns empty custom commands when only defaults and access log flags exist', function () {
    $existingConfig = Yaml::dump([
        'services' => [
            'traefik' => [
                'command' => [
                    '--ping=true',
                    '--entrypoints.http.address=:80',
                    '--accesslog=true',
                    '--accesslog.format=json',
                    '--providers.docker=true',
                ],
            ],
        ],
    ]);

    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);

    $customCommands = extractCustomProxyCommands($server, $existingConfig);

    expect($customCommands)->toBeArray()->toBeEmpty();
});
