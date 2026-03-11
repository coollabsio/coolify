<?php

namespace App\Actions\Database;

use App\Models\ServiceDatabase;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartDatabaseProxy
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|ServiceDatabase $database)
    {
        $databaseType = $database->database_type;
        $network = data_get($database, 'destination.network');
        $deploymentServer = data_get($database, 'destination.server');
        $containerName = data_get($database, 'uuid');
        $proxyContainerName = "{$database->uuid}-proxy";
        $isSSLEnabled = $database->enable_ssl ?? false;

        if ($database->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $databaseType = $database->databaseType();
            $network = $database->service->uuid;
            $deploymentServer = data_get($database, 'service.destination.server') ?? data_get($database, 'service.server');
            $containerName = "{$database->name}-{$database->service->uuid}";
        }
        $internalPort = match ($databaseType) {
            'standalone-mariadb', 'standalone-mysql' => 3306,
            'standalone-postgresql', 'standalone-supabase/postgres' => 5432,
            'standalone-redis', 'standalone-keydb', 'standalone-dragonfly' => 6379,
            'standalone-clickhouse' => 9000,
            'standalone-mongodb' => 27017,
            default => throw new \Exception("Unsupported database type: $databaseType"),
        };
        if ($isSSLEnabled) {
            $internalPort = match ($databaseType) {
                'standalone-redis', 'standalone-keydb', 'standalone-dragonfly' => 6380,
                default => $internalPort,
            };
        }

        if (! $deploymentServer instanceof Server) {
            $this->logWarning(sprintf(
                'Database proxy for %s is skipped because deployment server is missing.',
                $database->uuid
            ));

            return;
        }

        $proxyServer = $deploymentServer;
        $upstreamTarget = "{$containerName}:{$internalPort}";
        $proxyNetwork = $network;

        $edgeProxyServer = $this->resolveEdgeProxyServerForTeamId($this->resolveDatabaseTeamId($database));
        if ($edgeProxyServer instanceof Server && $edgeProxyServer->id !== $deploymentServer->id) {
            $remoteHost = $this->resolveRemoteHost($deploymentServer);
            if (! is_null($remoteHost)) {
                $proxyServer = $edgeProxyServer;
                $upstreamTarget = "{$remoteHost}:{$internalPort}";
                $proxyNetwork = null;
            } else {
                $this->logWarning(sprintf(
                    'Database proxy for %s is falling back to deployment server because remote host for edge forwarding is missing.',
                    $database->uuid
                ));
            }
        }

        $configuration_dir = $this->resolveConfigurationDirectory($database->uuid);
        $host_configuration_dir = $this->resolveHostConfigurationDirectory($database->uuid, $configuration_dir);
        $timeoutConfig = $this->buildProxyTimeoutConfig($database->public_port_timeout);
        $nginxconf = <<<EOF
    user  nginx;
    worker_processes  auto;

    error_log  /var/log/nginx/error.log;

    events {
        worker_connections  1024;
    }
    stream {
       server {
            listen $database->public_port;
            proxy_pass $upstreamTarget;
            $timeoutConfig
       }
    }
    EOF;
        $proxyServiceCompose = [
            'image' => 'nginx:stable-alpine',
            'container_name' => $proxyContainerName,
            'restart' => RESTART_MODE,
            'ports' => [
                "$database->public_port:$database->public_port",
            ],
            'volumes' => [
                [
                    'type' => 'bind',
                    'source' => "$host_configuration_dir/nginx.conf",
                    'target' => '/etc/nginx/nginx.conf',
                ],
            ],
            'healthcheck' => [
                'test' => [
                    'CMD-SHELL',
                    'stat /etc/nginx/nginx.conf || exit 1',
                ],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 3,
                'start_period' => '1s',
            ],
        ];

        $docker_compose = [
            'services' => [
                $proxyContainerName => $proxyServiceCompose,
            ],
        ];

        if (filled($proxyNetwork)) {
            $docker_compose['services'][$proxyContainerName]['networks'] = [$proxyNetwork];
            $docker_compose['networks'] = [
                $proxyNetwork => [
                    'external' => true,
                    'name' => $proxyNetwork,
                    'attachable' => true,
                ],
            ];
        }

        $dockercompose_base64 = base64_encode(Yaml::dump($docker_compose, 4, 2));
        $nginxconf_base64 = base64_encode($nginxconf);
        $this->runRemoteCommands(["docker rm -f $proxyContainerName"], $deploymentServer, false);
        if ($proxyServer->id !== $deploymentServer->id) {
            $this->runRemoteCommands(["docker rm -f $proxyContainerName"], $proxyServer, false);
        }

        try {
            $this->runRemoteCommands([
                "mkdir -p $configuration_dir",
                "echo '{$nginxconf_base64}' | base64 -d | tee $configuration_dir/nginx.conf > /dev/null",
                "echo '{$dockercompose_base64}' | base64 -d | tee $configuration_dir/docker-compose.yaml > /dev/null",
                "docker compose --project-directory {$configuration_dir} pull",
                "docker compose --project-directory {$configuration_dir} up -d",
            ], $proxyServer);
        } catch (\RuntimeException $e) {
            if ($this->isNonTransientError($e->getMessage())) {
                $database->update(['is_public' => false]);

                $team = data_get($database, 'environment.project.team')
                    ?? data_get($database, 'service.environment.project.team');

                $team?->notify(
                    new \App\Notifications\Container\ContainerRestarted(
                        "TCP Proxy for {$database->name} database has been disabled due to error: {$e->getMessage()}",
                        $proxyServer,
                    )
                );

                ray("Database proxy for {$database->name} disabled due to non-transient error: {$e->getMessage()}");

                return;
            }

            throw $e;
        }
    }

    protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
    {
        return instant_remote_process($commands, $server, $throwError);
    }

    protected function resolveConfigurationDirectory(string $databaseUuid): string
    {
        return database_proxy_dir($databaseUuid);
    }

    protected function resolveHostConfigurationDirectory(string $databaseUuid, ?string $configurationDirectory = null): string
    {
        $configurationDirectory ??= $this->resolveConfigurationDirectory($databaseUuid);
        if ($this->isDevelopmentEnvironment()) {
            return '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/'.$databaseUuid.'/proxy';
        }

        return $configurationDirectory;
    }

    protected function isDevelopmentEnvironment(): bool
    {
        if (app()->bound('config')) {
            return isDev();
        }

        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

        return $appEnv === 'local';
    }

    protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
    {
        if (is_null($teamId)) {
            return null;
        }

        return Server::query()
            ->where('team_id', $teamId)
            ->whereRelation('settings', 'is_master_domain_router_enabled', true)
            ->orderBy('id')
            ->first();
    }

    private function resolveDatabaseTeamId(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|ServiceDatabase $database): ?int
    {
        if ($database->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $teamId = data_get($database, 'service.environment.project.team_id');
            if (! is_null($teamId)) {
                return (int) $teamId;
            }
        }

        $teamId = data_get($database, 'environment.project.team_id');
        if (! is_null($teamId)) {
            return (int) $teamId;
        }

        $teamId = data_get($database, 'team.id');
        if (! is_null($teamId)) {
            return (int) $teamId;
        }

        return null;
    }

    private function resolveRemoteHost(Server $deploymentServer): ?string
    {
        $candidates = [
            data_get($deploymentServer, 'proxy.wireguard_ip'),
            data_get($deploymentServer, 'proxy.wg_ip'),
            data_get($deploymentServer, 'proxy.tunnel_ip'),
            data_get($deploymentServer, 'proxy.tunnel_host'),
            data_get($deploymentServer, 'proxy.tunnel_domain'),
            data_get($deploymentServer, 'ip'),
        ];

        foreach ($candidates as $candidate) {
            $normalizedHost = $this->normalizeRemoteHost((string) $candidate);
            if (! is_null($normalizedHost)) {
                return $normalizedHost;
            }
        }

        return null;
    }

    private function normalizeRemoteHost(string $rawHost): ?string
    {
        $host = trim($rawHost);
        if ($host === '') {
            return null;
        }

        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        } elseif (str_contains($host, '/')) {
            $parsedHost = parse_url('http://'.$host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        }

        $host = trim($host, '[]');
        if ($host === '') {
            return null;
        }

        if (str_contains($host, ':') && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parsedHost = parse_url('http://'.$host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        }

        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '['.$host.']';
        }

        return $host;
    }

    protected function logWarning(string $message): void
    {
        if (app()->bound('log')) {
            app('log')->warning($message);

            return;
        }

        error_log($message);
    }

    private function isNonTransientError(string $message): bool
    {
        $nonTransientPatterns = [
            'port is already allocated',
            'address already in use',
            'Bind for',
        ];

        foreach ($nonTransientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function buildProxyTimeoutConfig(?int $timeout): string
    {
        if ($timeout === null || $timeout < 1) {
            $timeout = 3600;
        }

        return "proxy_timeout {$timeout}s;";
    }
}
