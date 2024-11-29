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
        $internalPort = null;
        $type = $database->getMorphClass();
        $network = data_get($database, 'destination.network');
        $server = data_get($database, 'destination.server');
        $containerName = data_get($database, 'uuid');
        $proxyContainerName = "{$database->uuid}-proxy";
        if ($database->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $databaseType = $database->databaseType();
            // $connectPredefined = data_get($database, 'service.connect_to_docker_network');
            $network = $database->service->uuid;
            $server = data_get($database, 'service.destination.server');
            $proxyContainerName = "{$database->service->uuid}-proxy";
            switch ($databaseType) {
                case 'standalone-mariadb':
                    $type = \App\Models\StandaloneMariadb::class;
                    $containerName = "mariadb-{$database->service->uuid}";
                    break;
                case 'standalone-mongodb':
                    $type = \App\Models\StandaloneMongodb::class;
                    $containerName = "mongodb-{$database->service->uuid}";
                    break;
                case 'standalone-mysql':
                    $type = \App\Models\StandaloneMysql::class;
                    $containerName = "mysql-{$database->service->uuid}";
                    break;
                case 'standalone-postgresql':
                    $type = \App\Models\StandalonePostgresql::class;
                    $containerName = "postgresql-{$database->service->uuid}";
                    break;
                case 'standalone-redis':
                    $type = \App\Models\StandaloneRedis::class;
                    $containerName = "redis-{$database->service->uuid}";
                    break;
                case 'standalone-keydb':
                    $type = \App\Models\StandaloneKeydb::class;
                    $containerName = "keydb-{$database->service->uuid}";
                    break;
                case 'standalone-dragonfly':
                    $type = \App\Models\StandaloneDragonfly::class;
                    $containerName = "dragonfly-{$database->service->uuid}";
                    break;
                case 'standalone-clickhouse':
                    $type = \App\Models\StandaloneClickhouse::class;
                    $containerName = "clickhouse-{$database->service->uuid}";
                    break;
            }
        }
        if ($type === \App\Models\StandaloneRedis::class) {
            $internalPort = 6379;
        } elseif ($type === \App\Models\StandalonePostgresql::class) {
            $internalPort = 5432;
        } elseif ($type === \App\Models\StandaloneMongodb::class) {
            $internalPort = 27017;
        } elseif ($type === \App\Models\StandaloneMysql::class) {
            $internalPort = 3306;
        } elseif ($type === \App\Models\StandaloneMariadb::class) {
            $internalPort = 3306;
        } elseif ($type === \App\Models\StandaloneKeydb::class) {
            $internalPort = 6379;
        } elseif ($type === \App\Models\StandaloneDragonfly::class) {
            $internalPort = 6379;
        } elseif ($type === \App\Models\StandaloneClickhouse::class) {
            $internalPort = 9000;
        }
        $configuration_dir = database_proxy_dir($database->uuid);
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
       }
    }
    EOF;
        $dockerfile = <<< 'EOF'
    FROM nginx:stable-alpine

    COPY nginx.conf /etc/nginx/nginx.conf
    EOF;
        $docker_compose = [
            'services' => [
                $proxyContainerName => [
                    'build' => [
                        'context' => $configuration_dir,
                        'dockerfile' => 'Dockerfile',
                    ],
                    'image' => 'nginx:stable-alpine',
                    'container_name' => $proxyContainerName,
                    'restart' => RESTART_MODE,
                    'ports' => [
                        "$database->public_port:$database->public_port",
                    ],
                    'networks' => [
                        $network,
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
        $dockerfile_base64 = base64_encode($dockerfile);
        instant_remote_process(["docker rm -f $proxyContainerName"], $server, false);
        instant_remote_process([
            "mkdir -p $configuration_dir",
            "echo '{$dockerfile_base64}' | base64 -d | tee $configuration_dir/Dockerfile > /dev/null",
            "echo '{$nginxconf_base64}' | base64 -d | tee $configuration_dir/nginx.conf > /dev/null",
            "echo '{$dockercompose_base64}' | base64 -d | tee $configuration_dir/docker-compose.yaml > /dev/null",
            "docker compose --project-directory {$configuration_dir} pull",
            "docker compose --project-directory {$configuration_dir} up --build -d",
        ], $server);
    }
}
