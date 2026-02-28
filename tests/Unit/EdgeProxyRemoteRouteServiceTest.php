<?php

use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Services\EdgeProxyRemoteRouteService;

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
    $firstWriteCommands = implode("\n", $manager->calls[0]['commands']);

    expect($firstWriteCommands)->toContain($expectedPath)
        ->and($firstWriteCommands)->toContain('tee');

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
        ->and($secondWriteCommands)->toContain('tee');

    preg_match("/echo '([^']+)' \\| base64 -d/", $manager->calls[1]['commands'][1], $secondPayloadMatches);
    $secondPayload = base64_decode($secondPayloadMatches[1]);
    expect($secondPayload)->toContain('http://10.8.0.15:9020');

    $manager->deleteServiceWithServer($service, $edgeProxyServer);

    expect($manager->calls)->toHaveCount(3);
    $deleteCommands = implode("\n", $manager->calls[2]['commands']);
    expect($deleteCommands)->toContain("rm -f '$expectedPath'");
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
