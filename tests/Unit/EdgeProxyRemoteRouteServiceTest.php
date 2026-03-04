<?php

use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Services\EdgeProxyRemoteRouteService;
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
