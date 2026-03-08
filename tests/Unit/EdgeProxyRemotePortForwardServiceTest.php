<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use Illuminate\Container\Container;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

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

it('mirrors application published tcp ports onto the edge server for remote deployments', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 1;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 2;
    $deploymentServer->ip = '10.8.0.15';

    $application = new Application;
    $application->uuid = 'application-port-forward';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '25565:25565';

    $manager = new class extends EdgeProxyRemotePortForwardService
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

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1)
        ->and($manager->calls[0]['server_id'])->toBe(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 25565;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.15:25565;');

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*docker-compose\\.yaml/", $manager->calls[0]['commands'][3], $composeMatches);
    $dockerCompose = base64_decode($composeMatches[1] ?? '');
    $parsedCompose = Yaml::parse($dockerCompose);

    expect(data_get($parsedCompose, 'services.application-application-port-forward-edge-port-proxy.container_name'))
        ->toBe('application-application-port-forward-edge-port-proxy')
        ->and(data_get($parsedCompose, 'services.application-application-port-forward-edge-port-proxy.ports'))
        ->toBe(['25565:25565']);
});

it('mirrors application published udp ports onto the edge server for remote deployments', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 11;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 12;
    $deploymentServer->ip = '10.8.0.25';

    $application = new Application;
    $application->uuid = 'application-udp-port-forward';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '19132:19132/udp';

    $manager = new class extends EdgeProxyRemotePortForwardService
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

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 19132 udp;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.25:19132;');

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*docker-compose\\.yaml/", $manager->calls[0]['commands'][3], $composeMatches);
    $dockerCompose = base64_decode($composeMatches[1] ?? '');
    $parsedCompose = Yaml::parse($dockerCompose);

    expect(data_get($parsedCompose, 'services.application-application-udp-port-forward-edge-port-proxy.ports'))
        ->toBe(['19132:19132/udp']);
});

it('mirrors published compose ports for services onto the edge server', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 21;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 22;
    $deploymentServer->ip = '10.8.0.35';

    $service = new Service;
    $service->uuid = 'service-port-forward';
    $service->docker_compose_raw = <<<'YAML'
services:
  mc:
    ports:
      - "25565:25565"
      - "19132:19132/udp"
YAML;

    $manager = new class extends EdgeProxyRemotePortForwardService
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

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1)
        ->and($manager->calls[0]['server_id'])->toBe(21);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 25565;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.35:25565;')
        ->and($nginxConf)->toContain('listen 19132 udp;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.35:19132;');
});

it('mirrors compose application published ports resolved from compose environment defaults', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 23;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 24;
    $deploymentServer->ip = '10.8.0.55';

    $application = new Application;
    $application->uuid = 'application-compose-env-default-port';
    $application->build_pack = 'dockercompose';
    $application->docker_compose_raw = <<<'YAML'
services:
  mc:
    ports:
      - "${PORT}:25565"
    environment:
      - PORT=${PORT:-25565}
YAML;

    $manager = new class extends EdgeProxyRemotePortForwardService
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

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 25565;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.55:25565;');
});

it('warns and removes stale application edge port proxy when remote host is missing', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 31;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 32;
    $deploymentServer->ip = '';

    $application = new Application;
    $application->uuid = 'application-missing-remote-host';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '25565:25565';

    $manager = new class extends EdgeProxyRemotePortForwardService
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

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('remote host is missing')
        ->and($manager->calls)->toHaveCount(1)
        ->and($manager->calls[0]['commands'][0])->toContain('docker rm -f');
});

it('skips reserved edge ports while keeping other published application ports', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 41;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 42;
    $deploymentServer->ip = '10.8.0.45';

    $application = new Application;
    $application->uuid = 'application-reserved-ports';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '80:80,25565:25565,443:443';

    $manager = new class extends EdgeProxyRemotePortForwardService
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

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('reserved on the edge server')
        ->and($warnings[1])->toContain('reserved on the edge server')
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 25565;')
        ->and($nginxConf)->not->toContain('listen 80;')
        ->and($nginxConf)->not->toContain('listen 443;');
});

it('deletes application edge port proxy containers', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 51;

    $application = new Application;
    $application->uuid = 'application-delete-port-proxy';

    $manager = new class extends EdgeProxyRemotePortForwardService
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

    $manager->deleteApplicationWithServer($application, $edgeProxyServer);

    expect($manager->calls)->toHaveCount(1)
        ->and($manager->calls[0]['server_id'])->toBe(51)
        ->and($manager->calls[0]['commands'][0])->toContain('application-application-delete-port-proxy-edge-port-proxy');
});
