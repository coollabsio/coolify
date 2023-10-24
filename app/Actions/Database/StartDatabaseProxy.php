<?php

namespace App\Actions\Database;

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

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb $database)
    {
        $internalPort = null;
        if ($database->getMorphClass() === 'App\Models\StandaloneRedis') {
            $internalPort = 6379;
        } else if ($database->getMorphClass() === 'App\Models\StandalonePostgresql') {
            $internalPort = 5432;
        } else if ($database->getMorphClass() === 'App\Models\StandaloneMongodb') {
            $internalPort = 27017;
        } else if ($database->getMorphClass() === 'App\Models\StandaloneMysql') {
            $internalPort = 3306;
        } else if ($database->getMorphClass() === 'App\Models\StandaloneMariadb') {
            $internalPort = 3306;
        }
        $containerName = "{$database->uuid}-proxy";
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
            proxy_pass $database->uuid:$internalPort;
       }
    }
    EOF;
        $dockerfile = <<< EOF
    FROM nginx:stable-alpine

    COPY nginx.conf /etc/nginx/nginx.conf
    EOF;
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $containerName => [
                    'build' => [
                        'context' => $configuration_dir,
                        'dockerfile' => 'Dockerfile',
                    ],
                    'image' => "nginx:stable-alpine",
                    'container_name' => $containerName,
                    'restart' => RESTART_MODE,
                    'ports' => [
                        "$database->public_port:$database->public_port",
                    ],
                    'networks' => [
                        $database->destination->network,
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            'stat /etc/nginx/nginx.conf || exit 1',
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 3,
                        'start_period' => '1s'
                    ],
                ]
            ],
            'networks' => [
                $database->destination->network => [
                    'external' => true,
                    'name' => $database->destination->network,
                    'attachable' => true,
                ]
            ]
        ];
        $dockercompose_base64 = base64_encode(Yaml::dump($docker_compose, 4, 2));
        $nginxconf_base64 = base64_encode($nginxconf);
        $dockerfile_base64 = base64_encode($dockerfile);
        instant_remote_process([
            "mkdir -p $configuration_dir",
            "echo '{$dockerfile_base64}' | base64 -d > $configuration_dir/Dockerfile",
            "echo '{$nginxconf_base64}' | base64 -d > $configuration_dir/nginx.conf",
            "echo '{$dockercompose_base64}' | base64 -d > $configuration_dir/docker-compose.yaml",
            "docker compose --project-directory {$configuration_dir} up --build -d",
        ], $database->destination->server);
    }
}
