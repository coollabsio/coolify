<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Psr\Log\NullLogger;

$originalLogger = null;

beforeEach(function () use (&$originalLogger) {
    $container = Container::getInstance();
    $originalLogger = $container->bound('log') ? $container->make('log') : null;
    $container->instance('log', new NullLogger);
});

afterEach(function () use (&$originalLogger) {
    if (is_null($originalLogger)) {
        return;
    }

    Container::getInstance()->instance('log', $originalLogger);
});

it('generates edge traefik config for a remote domain route', function () {
    $service = new EdgeProxyRemoteRouteService;

    $config = $service->generateTraefikConfig('service-uuid', [[
        'host' => 'demo.example.com',
        'path' => '/',
        'upstream_url' => 'http://10.8.0.15:9010',
    ]]);

    expect(data_get($config, 'http.middlewares.edge-service-uuid-redirect-to-https.redirectScheme.scheme'))->toBe('https')
        ->and(data_get($config, 'http.routers.edge-service-uuid-http-1.rule'))->toBe('Host(`demo.example.com`)')
        ->and(data_get($config, 'http.routers.edge-service-uuid-http-1.entryPoints'))->toBe(['http'])
        ->and(data_get($config, 'http.routers.edge-service-uuid-http-1.middlewares'))->toBe(['edge-service-uuid-redirect-to-https'])
        ->and(data_get($config, 'http.routers.edge-service-uuid-https-1.entryPoints'))->toBe(['https'])
        ->and(data_get($config, 'http.routers.edge-service-uuid-https-1.tls.certResolver'))->toBe('letsencrypt')
        ->and(data_get($config, 'http.services.edge-service-uuid-svc-1.loadBalancer.servers.0.url'))->toBe('http://10.8.0.15:9010');
});

it('names edge route files with the deployment server uuid when available', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public function serviceRoutePath(Server $edgeProxyServer, string $serviceUuid, ?Server $deploymentServer = null): string
        {
            return $this->routeFilePath($edgeProxyServer, $serviceUuid, $deploymentServer);
        }

        public function applicationRoutePath(Server $edgeProxyServer, string $applicationUuid, ?Server $deploymentServer = null): string
        {
            return $this->applicationRouteFilePath($edgeProxyServer, $applicationUuid, $deploymentServer);
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->uuid = 'deployment-test-uuid';

    expect($manager->serviceRoutePath($edgeProxyServer, 'service-test-uuid', $deploymentServer))
        ->toBe('/tmp/proxy/dynamic/service-remote-from-deployment-test-uuid-service-test-uuid.yaml')
        ->and($manager->applicationRoutePath($edgeProxyServer, 'application-test-uuid', $deploymentServer))
        ->toBe('/tmp/proxy/dynamic/application-remote-from-deployment-test-uuid-application-test-uuid.yaml');
});

it('cleans stale edge route file variants when a deployment server uuid is available', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 99;
    $deploymentServer->uuid = 'deployment-test-uuid';
    $deploymentServer->ip = '10.8.0.99';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-test-uuid';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9010:3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://demo.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($manager->calls)->toHaveCount(1);

    $commands = implode("\n", $manager->calls[0]['commands']);
    expect($commands)
        ->toContain("service-remote-from-deployment-test-uuid-service-test-uuid.yaml")
        ->toContain("find '/tmp/proxy/dynamic' -maxdepth 1 -type f")
        ->toContain('service-remote-*service-test-uuid.yaml')
        ->toContain('service-remote-*service-test-uuid.yaml.tmp')
        ->toContain('-delete');
});

it('uses configured traefik entrypoints and cert resolver for remote routes', function () {
    $container = Container::getInstance();
    $hadOriginalConfig = $container->bound('config');
    $originalConfig = $hadOriginalConfig ? $container->make('config') : null;

    $container->instance('config', new Repository([
        'constants' => [
            'coolify' => [
                'proxy' => [
                    'traefik' => [
                        'entrypoints' => [
                            'http' => 'web',
                            'https' => 'websecure',
                        ],
                        'cert_resolver' => 'myresolver',
                    ],
                ],
            ],
        ],
    ]));

    try {
        $service = new EdgeProxyRemoteRouteService;
        $config = $service->generateTraefikConfig('service-uuid', [[
            'host' => 'demo.example.com',
            'path' => '/',
            'upstream_url' => 'http://10.8.0.15:9010',
        ]]);

        expect(data_get($config, 'http.routers.edge-service-uuid-http-1.entryPoints'))->toBe(['web'])
            ->and(data_get($config, 'http.routers.edge-service-uuid-https-1.entryPoints'))->toBe(['websecure'])
            ->and(data_get($config, 'http.routers.edge-service-uuid-https-1.tls.certResolver'))->toBe('myresolver');
    } finally {
        if ($hadOriginalConfig && ! is_null($originalConfig)) {
            $container->instance('config', $originalConfig);
        } else {
            unset($container['config']);
        }
    }
});

it('adds an insecure transport when an edge route falls back to the deployment proxy https entrypoint', function () {
    $service = new EdgeProxyRemoteRouteService;

    $config = $service->generateTraefikConfig('service-uuid', [[
        'host' => 'demo.example.com',
        'path' => '/',
        'upstream_url' => 'https://10.8.0.15:443',
        'pass_host_header' => true,
        'use_insecure_transport' => true,
    ]]);

    expect(data_get($config, 'http.services.edge-service-uuid-svc-1.loadBalancer.passHostHeader'))->toBeTrue()
        ->and(data_get($config, 'http.services.edge-service-uuid-svc-1.loadBalancer.serversTransport'))->toBe('edge-service-uuid-transport-1')
        ->and(data_get($config, 'http.serversTransports.edge-service-uuid-transport-1.insecureSkipVerify'))->toBeTrue();
});

it('does not warn when syncing service route without master domain routing enabled', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        protected function isMasterDomainRoutingEnabledForTeamId(?int $teamId): bool
        {
            return false;
        }

        protected function resolveEdgeProxyServerByTeamId(?int $teamId): ?Server
        {
            return null;
        }
    };

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 10;

    $service = new Service;
    $service->uuid = 'service-no-master-router';
    $service->setRelation('server', $deploymentServer);
    $service->setRelation('environment', (object) [
        'project' => (object) [
            'team_id' => 42,
        ],
    ]);

    $warnings = $manager->syncService($service);

    expect($warnings)->toBe([]);
});

it('does not warn when syncing application route without master domain routing enabled', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        protected function isMasterDomainRoutingEnabledForTeamId(?int $teamId): bool
        {
            return false;
        }

        protected function resolveEdgeProxyServerByTeamId(?int $teamId): ?Server
        {
            return null;
        }
    };

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 11;

    $application = new Application;
    $application->uuid = 'application-no-master-router';
    $application->setRelation('environment', (object) [
        'project' => (object) [
            'team_id' => 52,
        ],
    ]);

    $warnings = $manager->syncApplicationOnDeploymentServer($application, $deploymentServer);

    expect($warnings)->toBe([]);
});

it('returns warning when syncing application route with master domain routing enabled but no router is configured', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        protected function isMasterDomainRoutingEnabledForTeamId(?int $teamId): bool
        {
            return true;
        }

        protected function resolveEdgeProxyServerByTeamId(?int $teamId): ?Server
        {
            return null;
        }
    };

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 12;

    $application = new Application;
    $application->uuid = 'application-missing-master-router';
    $application->setRelation('environment', (object) [
        'project' => (object) [
            'team_id' => 53,
        ],
    ]);

    $warnings = $manager->syncApplicationOnDeploymentServer($application, $deploymentServer);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('no master domain router is configured for team 53');
});

it('creates, updates, and deletes a stable edge route file per service uuid', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 10;
    $deploymentServer->ip = '10.8.0.15';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-test-uuid';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9010:3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://demo.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1);

    $expectedPath = '/tmp/proxy/dynamic/service-remote-service-test-uuid.yaml';
    $expectedTempPath = '/tmp/proxy/dynamic/service-remote-service-test-uuid.yaml.tmp';
    $firstWriteCommands = implode("\n", $manager->calls[0]['commands']);

    expect($firstWriteCommands)->toContain($expectedPath)
        ->and($firstWriteCommands)->toContain($expectedTempPath)
        ->and($firstWriteCommands)->toContain('tee')
        ->and($firstWriteCommands)->toContain('mv');

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $firstPayloadMatches);
    $firstPayload = base64_decode($firstPayloadMatches[1]);
    expect($firstPayload)->toContain('http://10.8.0.15:9010');

    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9020:3000"
YAML;

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(2);

    $secondWriteCommands = implode("\n", $manager->calls[1]['commands']);
    expect($secondWriteCommands)->toContain($expectedPath)
        ->and($secondWriteCommands)->toContain($expectedTempPath)
        ->and($secondWriteCommands)->toContain('tee')
        ->and($secondWriteCommands)->toContain('mv');

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[1]['commands'][1], $secondPayloadMatches);
    $secondPayload = base64_decode($secondPayloadMatches[1]);
    expect($secondPayload)->toContain('http://10.8.0.15:9020');

    $manager->deleteServiceWithServer($service, $edgeProxyServer);

    expect($manager->calls)->toHaveCount(3);
    $deleteCommands = implode("\n", $manager->calls[2]['commands']);
    expect($deleteCommands)->toContain("rm -f '$expectedPath' '$expectedTempPath'");
});

it('creates, updates, and deletes a stable edge route file per application uuid', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 30;
    $deploymentServer->ip = '10.8.0.30';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-test-uuid';
    $application->build_pack = 'nixpacks';
    $application->fqdn = 'https://app.example.com:3000';
    $application->ports_mappings = '9010:3000';

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1);

    $expectedPath = '/tmp/proxy/dynamic/application-remote-application-test-uuid.yaml';
    $expectedTempPath = '/tmp/proxy/dynamic/application-remote-application-test-uuid.yaml.tmp';
    $firstWriteCommands = implode("\n", $manager->calls[0]['commands']);

    expect($firstWriteCommands)->toContain($expectedPath)
        ->and($firstWriteCommands)->toContain($expectedTempPath)
        ->and($firstWriteCommands)->toContain('tee')
        ->and($firstWriteCommands)->toContain('mv');

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $firstPayloadMatches);
    $firstPayload = base64_decode($firstPayloadMatches[1]);
    expect($firstPayload)->toContain('http://10.8.0.30:9010');

    $application->ports_mappings = '9020:3000';

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(2);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[1]['commands'][1], $secondPayloadMatches);
    $secondPayload = base64_decode($secondPayloadMatches[1]);
    expect($secondPayload)->toContain('http://10.8.0.30:9020');

    $manager->deleteApplicationWithServer($application, $edgeProxyServer);

    expect($manager->calls)->toHaveCount(3);
    $deleteCommands = implode("\n", $manager->calls[2]['commands']);
    expect($deleteCommands)->toContain("rm -f '$expectedPath' '$expectedTempPath'");
});

it('creates edge route for docker compose application domains using compose service ports', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 31;
    $deploymentServer->ip = '10.8.0.31';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-compose-route';
    $application->build_pack = 'dockercompose';
    $application->docker_compose_domains = json_encode([
        'web' => ['domain' => 'https://compose-app.example.com:3000'],
    ]);
    $application->docker_compose_raw = <<<'YAML'
services:
  web:
    ports:
      - "9030:3000"
YAML;

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('Host(`compose-app.example.com`)')
        ->and($payload)->toContain('http://10.8.0.31:9030');
});

it('returns actionable warning and does not write route file when application published host port is missing', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 32;
    $deploymentServer->ip = '10.8.0.32';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-missing-port';
    $application->build_pack = 'nixpacks';
    $application->fqdn = 'https://missing-port.example.com:3000';
    $application->ports_mappings = null;

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain('published host port could not be resolved')
        ->and(implode("\n", $manager->calls[0]['commands']))->toContain('/tmp/proxy/dynamic/application-remote-application-missing-port.yaml')
        ->and(implode("\n", $manager->calls[0]['commands']))->not->toContain('tee');
});

it('keeps application edge route files for HSTS-sensitive domains by falling back to the deployment proxy https entrypoint', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 32;
    $deploymentServer->ip = '10.8.0.32';
    $deploymentServer->proxy = ['type' => 'TRAEFIK'];

    $application = new Application;
    $application->uuid = 'application-missing-port-fallback';
    $application->build_pack = 'nixpacks';
    $application->fqdn = 'https://missing-port.example.com:3000';
    $application->ports_mappings = null;
    $application->ports_exposes = '3000';

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and(collect($warnings)->contains(fn (string $warning) => str_contains($warning, 'deployment server HTTPS proxy instead')))
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('https://10.8.0.32:443')
        ->and($payload)->toContain('passHostHeader: true')
        ->and($payload)->toContain('serversTransport: edge-application-missing-port-fallback-transport-1')
        ->and($payload)->toContain('insecureSkipVerify: true')
        ->and($payload)->toContain('certResolver: letsencrypt');
});

it('does not fall back through the deployment proxy when an application domain targets an unknown internal port', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 33;
    $deploymentServer->ip = '10.8.0.33';
    $deploymentServer->proxy = ['type' => 'TRAEFIK'];

    $application = new Application;
    $application->uuid = 'application-invalid-fallback-port';
    $application->build_pack = 'nixpacks';
    $application->fqdn = 'https://invalid-port.example.com:9999';
    $application->ports_mappings = null;
    $application->ports_exposes = '3000,4000';

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain('published host port could not be resolved')
        ->and(collect($warnings)->contains(fn (string $warning) => ! str_contains($warning, 'deployment server HTTPS proxy instead')))->toBeTrue()
        ->and(implode("\n", $manager->calls[0]['commands']))->toContain('/tmp/proxy/dynamic/application-remote-application-invalid-fallback-port.yaml')
        ->and(implode("\n", $manager->calls[0]['commands']))->not->toContain('tee');
});

it('keeps valid application edge routes when one domain port cannot be resolved and returns warning only for invalid domain', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 33;
    $deploymentServer->ip = '10.8.0.33';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-partial-routes';
    $application->build_pack = 'dockercompose';
    $application->docker_compose_domains = json_encode([
        'web' => ['domain' => 'https://good-app.example.com:3000,https://bad-app.example.com:9999'],
    ]);
    $application->docker_compose_raw = <<<'YAML'
services:
  web:
    ports:
      - "9060:3000"
      - "9070:4000"
YAML;

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain('published host port could not be resolved')
        ->and($manager->calls)->toHaveCount(1);

    $writeCommands = implode("\n", $manager->calls[0]['commands']);
    expect($writeCommands)->toContain('/tmp/proxy/dynamic/application-remote-application-partial-routes.yaml')
        ->and($writeCommands)->toContain('tee')
        ->and($writeCommands)->not->toContain('rm -f');

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('Host(`good-app.example.com`)')
        ->and($payload)->not->toContain('Host(`bad-app.example.com`)')
        ->and($payload)->toContain('http://10.8.0.33:9060');
});

it('does not generate edge route file when published port cannot be resolved and returns actionable warning', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 11;
    $deploymentServer->ip = '10.8.0.16';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-without-port';
    $service->docker_compose_raw = <<<'YAML'
services:
  app: {}
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://broken.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain('published host port could not be resolved')
        ->and(implode("\n", $manager->calls[0]['commands']))->toContain('/tmp/proxy/dynamic/service-remote-service-without-port.yaml')
        ->and(implode("\n", $manager->calls[0]['commands']))->not->toContain('tee');
});

it('keeps service edge route files for HSTS-sensitive domains by falling back to the deployment proxy https entrypoint', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 11;
    $deploymentServer->ip = '10.8.0.16';
    $deploymentServer->proxy = ['type' => 'TRAEFIK'];

    $service = new Service;
    $service->uuid = 'service-without-port-fallback';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://broken.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and(collect($warnings)->contains(fn (string $warning) => str_contains($warning, 'deployment server HTTPS proxy instead')))
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('https://10.8.0.16:443')
        ->and($payload)->toContain('Host(`broken.example.com`)')
        ->and($payload)->toContain('insecureSkipVerify: true')
        ->and($payload)->toContain('certResolver: letsencrypt');
});

it('keeps valid edge routes when one domain port cannot be resolved and returns warning only for invalid domain', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 13;
    $deploymentServer->ip = '10.8.0.18';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-partial-routes';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9010:3000"
      - "9020:4000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://good.example.com:3000,https://bad.example.com:9999';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain('published host port could not be resolved')
        ->and($manager->calls)->toHaveCount(1);

    $writeCommands = implode("\n", $manager->calls[0]['commands']);
    expect($writeCommands)->toContain('/tmp/proxy/dynamic/service-remote-service-partial-routes.yaml')
        ->and($writeCommands)->toContain('tee')
        ->and($writeCommands)->not->toContain('rm -f');

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('Host(`good.example.com`)')
        ->and($payload)->not->toContain('Host(`bad.example.com`)')
        ->and($payload)->toContain('http://10.8.0.18:9010');
});

it('does not generate edge route file when remote host is missing and returns actionable warning', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 12;
    $deploymentServer->ip = '';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-without-tunnel-host';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9010:3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://missing-tunnel.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain('remote host is missing')
        ->and(implode("\n", $manager->calls[0]['commands']))->toContain('/tmp/proxy/dynamic/service-remote-service-without-tunnel-host.yaml')
        ->and(implode("\n", $manager->calls[0]['commands']))->not->toContain('tee');
});

it('returns warning instead of throwing when edge route file write fails', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            if (str_contains(implode("\n", $commands), 'tee')) {
                throw new RuntimeException('edge ssh unavailable');
            }

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 14;
    $deploymentServer->ip = '10.8.0.19';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-write-failure';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9010:3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://write-failure.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and(collect($warnings)->contains(fn (string $warning) => str_contains($warning, 'failed to write dynamic route configuration')))
        ->and($manager->calls)->toHaveCount(1);
});

it('normalizes remote tunnel host values before generating upstream url', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 15;
    $deploymentServer->ip = '';
    $deploymentServer->proxy = ['type' => 'NONE', 'tunnel_host' => 'https://10.8.0.20:9443/path'];

    $service = new Service;
    $service->uuid = 'service-normalized-host';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9010:3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://normalized.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);
    expect($warnings)->toBe([]);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('http://10.8.0.20:9010')
        ->and($payload)->not->toContain('9443');
});

it('resolves published port when application name differs but compose has a single service with ports', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 16;
    $deploymentServer->ip = '10.8.0.21';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-single-compose-fallback';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9030:3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'web';
    $application->fqdn = 'https://single-fallback.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);
    expect($warnings)->toBe([]);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('http://10.8.0.21:9030');
});

it('ignores udp published ports when resolving upstream for edge routes', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 17;
    $deploymentServer->ip = '10.8.0.22';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-udp-filtering';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9010:3000/udp"
      - "9020:3000/tcp"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://udp-filtering.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);
    expect($warnings)->toBe([]);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('http://10.8.0.22:9020')
        ->and($payload)->not->toContain('http://10.8.0.22:9010');
});

it('returns warning for invalid domains while keeping valid remote edge routes', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 18;
    $deploymentServer->ip = '10.8.0.23';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-invalid-domain-warning';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9040:3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://valid.example.com:3000,https://:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and(collect($warnings)->contains(fn (string $warning) => str_contains($warning, 'domain format is invalid')))
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('Host(`valid.example.com`)')
        ->and($payload)->toContain('http://10.8.0.23:9040')
        ->and($payload)->not->toContain('https://:3000');
});

it('returns warning for unsupported domain protocols while keeping valid http routes', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 19;
    $deploymentServer->ip = '10.8.0.24';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-unsupported-protocol-warning';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9050:3000"
      - "25565:25565"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://valid-http.example.com:3000,tcp://minecraft.example.com:25565';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and(collect($warnings)->contains(fn (string $warning) => str_contains($warning, 'protocol "tcp" is not supported for edge remote routing')))
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('Host(`valid-http.example.com`)')
        ->and($payload)->toContain('http://10.8.0.24:9050')
        ->and($payload)->not->toContain('minecraft.example.com');
});

it('returns warning when remote tunnel ip overlaps with an edge docker subnet', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            if (str_contains(implode("\n", $commands), 'docker network inspect')) {
                return "10.8.0.0/24\n172.18.0.0/16\n";
            }

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->exists = true;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 20;
    $deploymentServer->ip = '10.8.0.40';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $service = new Service;
    $service->uuid = 'service-overlap-warning';
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    ports:
      - "9060:3000"
YAML;

    $application = new ServiceApplication;
    $application->name = 'app';
    $application->fqdn = 'https://overlap.example.com:3000';

    $service->setRelation('applications', collect([$application]));
    $application->setRelation('service', $service);

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and(collect($warnings)->contains(fn (string $warning) => str_contains($warning, 'overlaps edge Docker network subnet 10.8.0.0/24')));

    $writeCall = collect($manager->calls)->first(
        fn (array $call) => str_contains(implode("\n", $call['commands']), 'base64 -d | tee')
    );
    expect($writeCall)->not->toBeNull();

    preg_match("/echo '([^']+)' \\| base64 -d/", $writeCall['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('Host(`overlap.example.com`)')
        ->and($payload)->toContain('http://10.8.0.40:9060');
});

it('generates path prefix rule when route contains a non-root path', function () {
    $service = new EdgeProxyRemoteRouteService;

    $config = $service->generateTraefikConfig('service-path-uuid', [[
        'host' => 'api.example.com',
        'path' => '/v1',
        'upstream_url' => 'http://10.8.0.41:9070',
    ]]);

    expect(data_get($config, 'http.routers.edge-service-path-uuid-http-1.rule'))
        ->toBe('Host(`api.example.com`) && PathPrefix(`/v1`)')
        ->and(data_get($config, 'http.routers.edge-service-path-uuid-https-1.rule'))
        ->toBe('Host(`api.example.com`) && PathPrefix(`/v1`)');
});

it('deletes application edge route when deployment server is the same as edge proxy server', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 33;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 33;
    $deploymentServer->ip = '10.8.0.33';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-edge-self';
    $application->build_pack = 'nixpacks';
    $application->fqdn = 'https://self.example.com:3000';
    $application->ports_mappings = '9071:3000';

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1)
        ->and(implode("\n", $manager->calls[0]['commands']))->toContain('/tmp/proxy/dynamic/application-remote-application-edge-self.yaml')
        ->and(implode("\n", $manager->calls[0]['commands']))->not->toContain('tee');
});

it('normalizes ipv6 tunnel host for application upstream urls', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 34;
    $deploymentServer->ip = '2001:db8::1';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-ipv6-host';
    $application->build_pack = 'nixpacks';
    $application->fqdn = 'https://ipv6.example.com:3000';
    $application->ports_mappings = '9072:3000';

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);
    expect($warnings)->toBe([]);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('http://[2001:db8::1]:9072');
});

it('resolves docker compose application ports when domain service uses dashed name and compose uses underscore', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 35;
    $deploymentServer->ip = '10.8.0.35';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-compose-normalized-service';
    $application->build_pack = 'dockercompose';
    $application->docker_compose_domains = json_encode([
        'my-app' => ['domain' => 'https://normalized-service.example.com:3000'],
    ]);
    $application->docker_compose_raw = <<<'YAML'
services:
  my_app:
    ports:
      - "9073:3000"
YAML;

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);
    expect($warnings)->toBe([]);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('Host(`normalized-service.example.com`)')
        ->and($payload)->toContain('http://10.8.0.35:9073');
});

it('resolves compose application published port from environment variables', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 36;
    $deploymentServer->ip = '10.8.0.36';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-compose-env-port';
    $application->build_pack = 'dockercompose';
    $application->docker_compose_domains = json_encode([
        'web' => ['domain' => 'https://compose-env.example.com:3000'],
    ]);
    $application->docker_compose_raw = <<<'YAML'
services:
  web:
    ports:
      - "${APP_HOST_PORT:-9080}:3000"
YAML;
    $application->setRelation('environment_variables', collect([
        (object) ['key' => 'APP_HOST_PORT', 'value' => '9091'],
    ]));

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);
    expect($warnings)->toBe([]);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('http://10.8.0.36:9091');
});

it('resolves compose application published port from compose environment defaults', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 37;
    $deploymentServer->ip = '10.8.0.37';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-compose-env-default-port';
    $application->build_pack = 'dockercompose';
    $application->docker_compose_domains = json_encode([
        'web' => ['domain' => 'https://compose-env-default.example.com:3000'],
    ]);
    $application->docker_compose_raw = <<<'YAML'
services:
  web:
    ports:
      - "${APP_HOST_PORT}:3000"
    environment:
      - APP_HOST_PORT=${APP_HOST_PORT:-9082}
YAML;

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);
    expect($warnings)->toBe([]);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('http://10.8.0.37:9082');
});

it('returns warning for unsupported application domain protocol while preserving valid http route', function () {
    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 0;
    $edgeProxyServer->shouldReceive('proxyType')->andReturn('TRAEFIK');
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/proxy');

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 37;
    $deploymentServer->ip = '10.8.0.37';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $application = new Application;
    $application->uuid = 'application-unsupported-protocol';
    $application->build_pack = 'nixpacks';
    $application->fqdn = 'https://valid-app.example.com:3000,tcp://minecraft.example.com:25565';
    $application->ports_mappings = '9074:3000,25565:25565';

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->not->toBeEmpty()
        ->and(collect($warnings)->contains(fn (string $warning) => str_contains($warning, 'protocol "tcp" is not supported for edge remote routing')))
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[0]['commands'][1], $payloadMatches);
    $payload = base64_decode($payloadMatches[1]);

    expect($payload)->toContain('Host(`valid-app.example.com`)')
        ->and($payload)->toContain('http://10.8.0.37:9074')
        ->and($payload)->not->toContain('minecraft.example.com');
});

it('deletes service edge route files from all team traefik servers', function () {
    $firstEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $firstEdgeProxyServer->id = 201;
    $firstEdgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/edge-201');

    $secondEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $secondEdgeProxyServer->id = 202;
    $secondEdgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/edge-202');

    $manager = new class($firstEdgeProxyServer, $secondEdgeProxyServer) extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        public function __construct(private Server $firstEdgeProxyServer, private Server $secondEdgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): \Illuminate\Support\Collection
        {
            return collect([$this->firstEdgeProxyServer, $this->secondEdgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $service = new Service;
    $service->uuid = 'service-delete-all-edge-servers';
    $service->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 42],
    ]);

    $manager->deleteService($service);

    expect($manager->calls)->toHaveCount(2)
        ->and($manager->calls[0]['server_id'])->toBe(201)
        ->and($manager->calls[0]['commands'][0])->toContain('/tmp/edge-201/dynamic/service-remote-service-delete-all-edge-servers.yaml')
        ->and($manager->calls[1]['server_id'])->toBe(202)
        ->and($manager->calls[1]['commands'][0])->toContain('/tmp/edge-202/dynamic/service-remote-service-delete-all-edge-servers.yaml');
});

it('deletes application edge route files from all team traefik servers', function () {
    $firstEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $firstEdgeProxyServer->id = 211;
    $firstEdgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/edge-211');

    $secondEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $secondEdgeProxyServer->id = 212;
    $secondEdgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/edge-212');

    $manager = new class($firstEdgeProxyServer, $secondEdgeProxyServer) extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        public function __construct(private Server $firstEdgeProxyServer, private Server $secondEdgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): \Illuminate\Support\Collection
        {
            return collect([$this->firstEdgeProxyServer, $this->secondEdgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $application = new Application;
    $application->uuid = 'application-delete-all-edge-servers';
    $application->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 52],
    ]);

    $manager->deleteApplication($application);

    expect($manager->calls)->toHaveCount(2)
        ->and($manager->calls[0]['server_id'])->toBe(211)
        ->and($manager->calls[0]['commands'][0])->toContain('/tmp/edge-211/dynamic/application-remote-application-delete-all-edge-servers.yaml')
        ->and($manager->calls[1]['server_id'])->toBe(212)
        ->and($manager->calls[1]['commands'][0])->toContain('/tmp/edge-212/dynamic/application-remote-application-delete-all-edge-servers.yaml');
});

it('returns cleanup failure details when deleting service edge route files hits an ssh error', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 301;
    $edgeProxyServer->name = 'edge-unreachable';
    $edgeProxyServer->shouldReceive('proxyPath')->andReturn('/tmp/edge-301');

    $manager = new class($edgeProxyServer) extends EdgeProxyRemoteRouteService
    {
        public function __construct(private Server $edgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): \Illuminate\Support\Collection
        {
            return collect([$this->edgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            throw new RuntimeException('ssh: connect to host 10.0.0.9 port 22: No route to host');
        }
    };

    $service = new Service;
    $service->uuid = 'service-delete-ssh-failure';
    $service->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 91],
    ]);

    $failures = $manager->deleteService($service);

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('Failed to delete edge proxy route file for service service-delete-ssh-failure')
        ->and($failures[0])->toContain('edge-unreachable (301)')
        ->and($failures[0])->toContain('No route to host');
});
