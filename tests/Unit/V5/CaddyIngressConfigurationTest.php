<?php

use App\Actions\V5\Proxy\GenerateCaddyIngressConfiguration;
use App\Actions\V5\Proxy\StartCaddyIngress;
use App\Actions\V5\Proxy\StopCaddyIngress;
use App\Models\V5\Application;
use App\Models\V5\ApplicationDomain;
use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('generates a caddy ingress compose file with health endpoint and application routes', function () {
    $application = new Application([
        'name' => 'nginx-test',
        'container_name' => 'coolify-v5-nginx-test',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    $application->setRelation('domains', new Collection([
        new ApplicationDomain([
            'domain' => 'nginx.example.com',
        ]),
        new ApplicationDomain([
            'domain' => 'www.nginx.example.com',
        ]),
    ]));

    $configuration = GenerateCaddyIngressConfiguration::run(new Collection([$application]));

    expect($configuration['compose'])->toContain('container_name: coolify-v5-caddy')
        ->and($configuration['compose'])->toContain("image: 'docker.io/library/caddy:2-alpine'")
        ->and($configuration['compose'])->toContain('80:80')
        ->and($configuration['compose'])->toContain('443:443')
        ->and($configuration['compose'])->toContain('./Caddyfile:/etc/caddy/Caddyfile:ro')
        ->and($configuration['compose'])->toContain('./apps:/etc/caddy/apps:ro')
        ->and($configuration['caddyfile'])->toContain('respond /coolify-health 200')
        ->and($configuration['caddyfile'])->toContain('respond 404')
        ->and($configuration['caddyfile'])->toContain('import apps/*.caddy')
        ->and($configuration['apps'])->toHaveCount(1)
        ->and($configuration['apps'][0]['caddyfile'])->toContain('nginx.example.com {')
        ->and($configuration['apps'][0]['caddyfile'])->toContain('www.nginx.example.com {')
        ->and($configuration['apps'][0]['caddyfile'])->toContain('reverse_proxy coolify-v5-nginx-test.default.coolify.internal:8080');
});

it('does not generate app routes for applications without domains', function () {
    $application = new Application([
        'name' => 'private-app',
        'container_name' => 'coolify-v5-private',
        'mesh_namespace' => 'default',
    ]);
    $application->setRelation('domains', new Collection);

    $configuration = GenerateCaddyIngressConfiguration::run(new Collection([$application]));

    expect($configuration['caddyfile'])
        ->toContain('respond /coolify-health 200')
        ->and($configuration['apps'])->toBe([]);
});

it('does not generate app routes when ingress is disabled even with domains', function () {
    $application = new Application([
        'name' => 'private-app',
        'container_name' => 'coolify-v5-private',
        'mesh_namespace' => 'default',
        'ingress_enabled' => false,
        'internal_port' => 8080,
    ]);
    $application->setRelation('domains', new Collection([
        new ApplicationDomain([
            'domain' => 'private.example.com',
        ]),
    ]));

    $configuration = GenerateCaddyIngressConfiguration::run(new Collection([$application]));

    expect($configuration['apps'])->toBe([]);
});

it('does not generate app routes when the internal port is missing', function () {
    $application = new Application([
        'name' => 'needs-port',
        'container_name' => 'coolify-v5-needs-port',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => null,
    ]);
    $application->setRelation('domains', new Collection([
        new ApplicationDomain([
            'domain' => 'needs-port.example.com',
        ]),
    ]));

    $configuration = GenerateCaddyIngressConfiguration::run(new Collection([$application]));

    expect($configuration['apps'])->toBe([]);
});

it('applies caddy ingress configuration through flux instead of ssh', function () {
    $server = new Server([
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '10.0.0.10',
        'capabilities' => ['coold', 'ingress'],
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyCaddyIngress')
        ->once()
        ->with(
            '100.64.0.10',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'respond /coolify-health 200')),
            []
        )
        ->andReturn('Caddy ingress applied.');
    app()->instance(FluxClient::class, $fluxClient);

    $result = StartCaddyIngress::run($server);

    expect($result)->toBe('Caddy ingress applied.');
});

it('does not start caddy ingress for non-ingress servers', function () {
    $server = new Server([
        'capabilities' => ['coold'],
    ]);

    $result = StartCaddyIngress::run($server);

    expect($result)->toBe('Server is not an ingress server.');
});

it('stops caddy ingress through flux instead of ssh', function () {
    $server = new Server([
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '10.0.0.10',
        'capabilities' => ['coold'],
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('stopCaddyIngress')
        ->once()
        ->with('100.64.0.10')
        ->andReturn('Caddy ingress stopped.');
    app()->instance(FluxClient::class, $fluxClient);

    $result = StopCaddyIngress::run($server);

    expect($result)->toBe('Caddy ingress stopped.');
});
