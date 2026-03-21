<?php

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Events\DatabaseProxyStopped;
use App\Models\ServiceDatabase;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Support\Facades\Event;

it('runs standalone database proxy on the master domain router server for remote deployments', function () {
    $edgeServer = \Mockery::mock(Server::class)->makePartial();
    $edgeServer->id = 1;

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 2;
    $deploymentServer->ip = '10.8.0.15';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $database = new StandalonePostgresql;
    $database->uuid = 'standalone-db-uuid';
    $database->name = 'standalone-db';
    $database->public_port = 15432;
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
        'network' => 'standalone-network',
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 123],
    ]);

    $action = new class($edgeServer) extends StartDatabaseProxy
    {
        public array $calls = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($database);

    expect($action->calls)->toHaveCount(3)
        ->and($action->calls[0]['server_id'])->toBe(2)
        ->and($action->calls[1]['server_id'])->toBe(1)
        ->and($action->calls[2]['server_id'])->toBe(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $action->calls[2]['commands'][1], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 15432;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.15:5432;');

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*docker-compose\\.yaml/", $action->calls[2]['commands'][2], $composeMatches);
    $dockerCompose = base64_decode($composeMatches[1] ?? '');
    expect($dockerCompose)->not->toContain('external: true')
        ->and($dockerCompose)->not->toContain('standalone-network');
});

it('keeps configurable database proxy timeout when routing through the master domain router server', function () {
    $edgeServer = \Mockery::mock(Server::class)->makePartial();
    $edgeServer->id = 3;

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 4;
    $deploymentServer->ip = '10.8.0.44';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $database = new StandalonePostgresql;
    $database->uuid = 'standalone-db-timeout-uuid';
    $database->name = 'standalone-db-timeout';
    $database->public_port = 15444;
    $database->public_port_timeout = 7200;
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
        'network' => 'standalone-timeout-network',
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 124],
    ]);

    $action = new class($edgeServer) extends StartDatabaseProxy
    {
        public array $calls = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($database);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $action->calls[2]['commands'][1], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');

    expect($nginxConf)->toContain('proxy_pass 10.8.0.44:5432;')
        ->and($nginxConf)->toContain('proxy_timeout 7200s;');
});

it('keeps standalone database proxy on deployment server when no master domain router server is configured', function () {
    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 2;
    $deploymentServer->ip = '10.8.0.25';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $database = new StandalonePostgresql;
    $database->uuid = 'standalone-db-local-uuid';
    $database->name = 'standalone-db-local';
    $database->public_port = 15433;
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
        'network' => 'standalone-network-local',
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 456],
    ]);

    $action = new class extends StartDatabaseProxy
    {
        public array $calls = [];

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return null;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($database);

    expect($action->calls)->toHaveCount(2)
        ->and($action->calls[0]['server_id'])->toBe(2)
        ->and($action->calls[1]['server_id'])->toBe(2);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $action->calls[1]['commands'][1], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('proxy_pass standalone-db-local-uuid:5432;');

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*docker-compose\\.yaml/", $action->calls[1]['commands'][2], $composeMatches);
    $dockerCompose = base64_decode($composeMatches[1] ?? '');
    expect($dockerCompose)->toContain('standalone-network-local')
        ->and($dockerCompose)->toContain('external: true');
});

it('runs service database proxy on master domain router server for remote deployments', function () {
    $edgeServer = \Mockery::mock(Server::class)->makePartial();
    $edgeServer->id = 11;

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 22;
    $deploymentServer->ip = '10.8.0.35';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $serviceDatabase = \Mockery::mock(ServiceDatabase::class)->makePartial();
    $serviceDatabase->uuid = 'service-db-uuid';
    $serviceDatabase->name = 'postgres';
    $serviceDatabase->public_port = 15434;
    $serviceDatabase->shouldReceive('getMorphClass')->andReturn(\App\Models\ServiceDatabase::class);
    $serviceDatabase->shouldReceive('databaseType')->andReturn('standalone-postgresql');
    $serviceDatabase->setRelation('service', (object) [
        'uuid' => 'service-uuid',
        'destination' => (object) ['server' => $deploymentServer],
        'environment' => (object) ['project' => (object) ['team_id' => 789]],
    ]);

    $action = new class($edgeServer) extends StartDatabaseProxy
    {
        public array $calls = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($serviceDatabase);

    expect($action->calls)->toHaveCount(3)
        ->and($action->calls[0]['server_id'])->toBe(22)
        ->and($action->calls[1]['server_id'])->toBe(11)
        ->and($action->calls[2]['server_id'])->toBe(11);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $action->calls[2]['commands'][1], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 15434;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.35:5432;')
        ->and($nginxConf)->not->toContain('postgres-service-uuid');
});

it('stops database proxy containers on deployment and master domain router servers', function () {
    $edgeServer = \Mockery::mock(Server::class)->makePartial();
    $edgeServer->id = 101;

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 202;

    $database = \Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->uuid = 'stopped-db-uuid';
    $database->shouldReceive('save')->once()->andReturnTrue();
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 555],
    ]);

    $action = new class($edgeServer) extends StopDatabaseProxy
    {
        public array $calls = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function dispatchDatabaseProxyStoppedEvent($database): void
        {
            // No-op in isolated unit test environment.
        }
    };

    $action->handle($database);

    expect($action->calls)->toHaveCount(2)
        ->and($action->calls[0]['server_id'])->toBe(202)
        ->and($action->calls[1]['server_id'])->toBe(101)
        ->and($action->calls[0]['commands'][0])->toBe('docker rm -f stopped-db-uuid-proxy')
        ->and($action->calls[1]['commands'][0])->toBe('docker rm -f stopped-db-uuid-proxy');
});

it('falls back to deployment server when master domain router exists but remote host is missing', function () {
    $edgeServer = \Mockery::mock(Server::class)->makePartial();
    $edgeServer->id = 41;

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 42;
    $deploymentServer->ip = '';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $database = new StandalonePostgresql;
    $database->uuid = 'standalone-db-fallback-uuid';
    $database->name = 'standalone-db-fallback';
    $database->public_port = 15440;
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
        'network' => 'fallback-network',
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 321],
    ]);

    $action = new class($edgeServer) extends StartDatabaseProxy
    {
        public array $calls = [];
        public array $warnings = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }

        protected function logWarning(string $message): void
        {
            $this->warnings[] = $message;
        }
    };

    $action->handle($database);

    expect($action->calls)->toHaveCount(2)
        ->and($action->calls[0]['server_id'])->toBe(42)
        ->and($action->calls[1]['server_id'])->toBe(42)
        ->and($action->warnings)->not->toBeEmpty()
        ->and($action->warnings[0])->toContain('falling back to deployment server');
});

it('keeps proxy on deployment server when master router server is the same server', function () {
    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 51;
    $deploymentServer->ip = '10.8.0.51';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $database = new StandalonePostgresql;
    $database->uuid = 'standalone-db-same-edge-uuid';
    $database->name = 'standalone-db-same-edge';
    $database->public_port = 15451;
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
        'network' => 'same-edge-network',
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 654],
    ]);

    $action = new class($deploymentServer) extends StartDatabaseProxy
    {
        public array $calls = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($database);

    expect($action->calls)->toHaveCount(2)
        ->and($action->calls[0]['server_id'])->toBe(51)
        ->and($action->calls[1]['server_id'])->toBe(51);
});

it('supports service database deployment server fallback from service.server when destination is missing', function () {
    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 61;
    $deploymentServer->ip = '10.8.0.61';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $serviceDatabase = \Mockery::mock(ServiceDatabase::class)->makePartial();
    $serviceDatabase->uuid = 'service-db-fallback-server-uuid';
    $serviceDatabase->name = 'postgres';
    $serviceDatabase->public_port = 15461;
    $serviceDatabase->shouldReceive('getMorphClass')->andReturn(\App\Models\ServiceDatabase::class);
    $serviceDatabase->shouldReceive('databaseType')->andReturn('standalone-postgresql');
    $serviceDatabase->setRelation('service', (object) [
        'uuid' => 'service-fallback-uuid',
        'server' => $deploymentServer,
        'environment' => (object) ['project' => (object) ['team_id' => 111]],
    ]);

    $action = new class extends StartDatabaseProxy
    {
        public array $calls = [];

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return null;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($serviceDatabase);

    expect($action->calls)->toHaveCount(2)
        ->and($action->calls[0]['server_id'])->toBe(61)
        ->and($action->calls[1]['server_id'])->toBe(61);
});

it('uses the dev host configuration path only for the bind mount source', function () {
    $action = new class extends StartDatabaseProxy
    {
        public function configurationDirectory(string $databaseUuid): string
        {
            return $this->resolveConfigurationDirectory($databaseUuid);
        }

        public function hostConfigurationDirectory(string $databaseUuid): string
        {
            return $this->resolveHostConfigurationDirectory($databaseUuid);
        }

        protected function isDevelopmentEnvironment(): bool
        {
            return true;
        }
    };

    expect($action->configurationDirectory('dev-db-uuid'))
        ->toBe('/data/coolify/databases/dev-db-uuid/proxy')
        ->and($action->hostConfigurationDirectory('dev-db-uuid'))
        ->toBe('/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/dev-db-uuid/proxy');
});

it('uses ssl internal redis port 6380 for remote database proxy upstream target', function () {
    $edgeServer = \Mockery::mock(Server::class)->makePartial();
    $edgeServer->id = 71;

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 72;
    $deploymentServer->ip = '10.8.0.72';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $database = \Mockery::mock(\App\Models\StandaloneRedis::class)->makePartial();
    $database->uuid = 'redis-ssl-db-uuid';
    $database->name = 'redis-ssl-db';
    $database->public_port = 16379;
    $database->enable_ssl = true;
    $database->database_type = 'standalone-redis';
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
        'network' => 'redis-network',
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 222],
    ]);

    $action = new class($edgeServer) extends StartDatabaseProxy
    {
        public array $calls = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($database);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $action->calls[2]['commands'][1], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');

    expect($nginxConf)->toContain('listen 16379;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.72:6380;');
});

it('disables public database proxy on non-transient startup errors', function () {
    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 81;
    $deploymentServer->ip = '10.8.0.81';
    $deploymentServer->proxy = ['type' => 'NONE'];

    $database = \Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->uuid = 'standalone-db-error-uuid';
    $database->name = 'standalone-db-error';
    $database->public_port = 15481;
    $database->shouldReceive('update')->once()->with(['is_public' => false])->andReturnTrue();
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
        'network' => 'error-network',
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 333],
    ]);

    $action = new class extends StartDatabaseProxy
    {
        public array $calls = [];

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            if (str_contains(implode("\n", $commands), 'up -d')) {
                throw new \RuntimeException('Bind for 0.0.0.0:15481 failed: port is already allocated');
            }

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return null;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($database);

    expect($action->calls)->toHaveCount(2)
        ->and($action->calls[1]['server_id'])->toBe(81);
});

it('does not try to stop database proxy when deployment server is missing', function () {
    $database = \Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->uuid = 'stop-missing-server-db-uuid';
    $database->setRelation('destination', (object) [
        'server' => null,
    ]);

    $action = new class extends StopDatabaseProxy
    {
        public array $calls = [];

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function dispatchDatabaseProxyStoppedEvent($database): void
        {
            // No-op in isolated unit test environment.
        }
    };

    $action->handle($database);

    expect($action->calls)->toBe([]);
});

it('stops proxy only once when edge and deployment server are the same', function () {
    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 91;

    $database = \Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->uuid = 'stop-same-edge-db-uuid';
    $database->shouldReceive('save')->once()->andReturnTrue();
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 444],
    ]);

    $action = new class($deploymentServer) extends StopDatabaseProxy
    {
        public array $calls = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function dispatchDatabaseProxyStoppedEvent($database): void
        {
            // No-op in isolated unit test environment.
        }
    };

    $action->handle($database);

    expect($action->calls)->toHaveCount(1)
        ->and($action->calls[0]['server_id'])->toBe(91)
        ->and($action->calls[0]['commands'][0])->toBe('docker rm -f stop-same-edge-db-uuid-proxy');
});

it('skips starting database proxy when deployment server is missing', function () {
    $database = new StandalonePostgresql;
    $database->uuid = 'missing-deployment-server-db-uuid';
    $database->name = 'missing-deployment-server-db';
    $database->public_port = 15500;
    $database->setRelation('destination', (object) [
        'server' => null,
        'network' => 'missing-network',
    ]);

    $action = new class extends StartDatabaseProxy
    {
        public array $warnings = [];

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }

        protected function logWarning(string $message): void
        {
            $this->warnings[] = $message;
        }
    };

    $action->handle($database);

    expect($action->warnings)->toHaveCount(1)
        ->and($action->warnings[0])->toContain('deployment server is missing');
});

it('normalizes remote host with scheme and path before building database upstream target', function () {
    $edgeServer = \Mockery::mock(Server::class)->makePartial();
    $edgeServer->id = 111;

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 112;
    $deploymentServer->ip = '';
    $deploymentServer->proxy = ['type' => 'NONE', 'tunnel_host' => 'https://10.8.0.112:8443/path'];

    $database = new StandalonePostgresql;
    $database->uuid = 'normalized-remote-host-db-uuid';
    $database->name = 'normalized-remote-host-db';
    $database->public_port = 15501;
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
        'network' => 'normalized-network',
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 987],
    ]);

    $action = new class($edgeServer) extends StartDatabaseProxy
    {
        public array $calls = [];

        public function __construct(private ?Server $edgeServer) {}

        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return $this->edgeServer;
        }

        protected function resolveConfigurationDirectory(string $databaseUuid): string
        {
            return "/tmp/database-proxy/{$databaseUuid}";
        }
    };

    $action->handle($database);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $action->calls[2]['commands'][1], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');

    expect($nginxConf)->toContain('proxy_pass 10.8.0.112:5432;')
        ->and($nginxConf)->not->toContain('8443');
});

it('dispatches database proxy stopped event with standalone database team id', function () {
    Event::fake([DatabaseProxyStopped::class]);

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 301;

    $database = \Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->uuid = 'stopped-db-event-uuid';
    $database->shouldReceive('save')->once()->andReturnTrue();
    $database->setRelation('destination', (object) [
        'server' => $deploymentServer,
    ]);
    $database->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 777],
    ]);

    $action = new class extends StopDatabaseProxy
    {
        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return null;
        }
    };

    $action->handle($database);

    Event::assertDispatched(DatabaseProxyStopped::class, fn (DatabaseProxyStopped $event) => $event->teamId === 777);
});

it('dispatches database proxy stopped event with service database team id', function () {
    Event::fake([DatabaseProxyStopped::class]);

    $deploymentServer = \Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 302;

    $database = \Mockery::mock(ServiceDatabase::class)->makePartial();
    $database->uuid = 'service-db-event-uuid';
    $database->shouldReceive('getMorphClass')->andReturn(ServiceDatabase::class);
    $database->shouldReceive('save')->once()->andReturnTrue();
    $database->setRelation('service', (object) [
        'destination' => (object) ['server' => $deploymentServer],
        'environment' => (object) ['project' => (object) ['team_id' => 778]],
    ]);

    $action = new class extends StopDatabaseProxy
    {
        protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
        {
            return null;
        }

        protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
        {
            return null;
        }
    };

    $action->handle($database);

    Event::assertDispatched(DatabaseProxyStopped::class, fn (DatabaseProxyStopped $event) => $event->teamId === 778);
});
