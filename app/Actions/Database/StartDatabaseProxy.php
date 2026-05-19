<?php

namespace App\Actions\Database;

use App\Models\ServiceDatabase;
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
        $server = data_get($database, 'destination.server');
        $containerName = data_get($database, 'uuid');
        $proxyContainerName = "{$database->uuid}-proxy";
        $isSSLEnabled = $database->enable_ssl ?? false;

        if ($database->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $databaseType = $database->databaseType();
            $network = $database->service->uuid;
            $server = data_get($database, 'service.destination.server');
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

        $configuration_dir = database_proxy_dir($database->uuid);
        $host_configuration_dir = $configuration_dir;
        if (isDev()) {
            $host_configuration_dir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/'.$database->uuid.'/proxy';
        }
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
            proxy_pass $containerName:$internalPort;
            $timeoutConfig
       }
    }
    EOF;
        $docker_compose = [
            'services' => [
                $proxyContainerName => [
                    'image' => 'nginx:stable-alpine',
                    'container_name' => $proxyContainerName,
                    'restart' => RESTART_MODE,
                    'ports' => [
                        "$database->public_port:$database->public_port",
                    ],
                    'networks' => [
                        $network,
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
                ],
            ],
            'networks' => [
                $network => [
                    'external' => true,
                    'name' => $network,
                    'attachable' => true,
                ],
            ],
        ];
        $dockercompose_base64 = base64_encode(Yaml::dump($docker_compose, 4, 2));
        $nginxconf_base64 = base64_encode($nginxconf);
        instant_remote_process(["docker rm -f $proxyContainerName"], $server, false);

        try {
            instant_remote_process([
                "mkdir -p $configuration_dir",
                "echo '{$nginxconf_base64}' | base64 -d | tee $configuration_dir/nginx.conf > /dev/null",
                "echo '{$dockercompose_base64}' | base64 -d | tee $configuration_dir/docker-compose.yaml > /dev/null",
                "docker compose --project-directory {$configuration_dir} pull",
                "docker compose --project-directory {$configuration_dir} up -d",
            ], $server);
        } catch (\RuntimeException $e) {
            if ($this->isNonTransientError($e->getMessage())) {
                $database->update(['is_public' => false]);

                $team = data_get($database, 'environment.project.team')
                    ?? data_get($database, 'service.environment.project.team');

                $team?->notify(
                    new \App\Notifications\Container\ContainerRestarted(
                        "TCP Proxy for {$database->name} database has been disabled due to error: {$e->getMessage()}",
                        $server,
                    )
                );

                ray("Database proxy for {$database->name} disabled due to non-transient error: {$e->getMessage()}");

                return;
            }

            throw $e;
        }
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
