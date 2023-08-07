<?php

namespace App\Actions\Database;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\StandalonePostgresql;
use Symfony\Component\Yaml\Yaml;

class StartPostgresql
{
    public function __invoke(Server $server, StandalonePostgresql $database)
    {
        $container_name = generate_container_name($database->uuid);
        $destination = $database->destination;
        $image = $database->image;
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $container_name => [
                    'image' => $image,
                    'container_name' => $container_name,
                    'environment'=> [
                        'POSTGRES_USER' => $database->postgres_user,
                        'POSTGRES_PASSWORD' => $database->postgres_password,
                        'POSTGRES_DB' => $database->postgres_db,
                    ],
                    'restart' => 'always',
                    'networks' => [
                        $destination->network,
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            'pg_isready',
                            '-d',
                            $database->postgres_db,
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 10,
                        'start_period' => '5s'
                    ],
                    'mem_limit' => $database->limits_memory,
                    'memswap_limit' => $database->limits_memory_swap,
                    'mem_swappiness' => $database->limits_memory_swappiness,
                    'mem_reservation' => $database->limits_memory_reservation,
                    'cpus' => $database->limits_cpus,
                    'cpuset' => $database->limits_cpuset,
                    'cpu_shares' => $database->limits_cpu_shares,
                ]
            ],
            'networks' => [
                $destination->network => [
                    'external' => false,
                    'name' => $destination->network,
                    'attachable' => true,
                ]
            ]
        ];
        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $activity = remote_process([
            "mkdir -p /tmp/{$container_name}",
            "echo '{$docker_compose_base64}' | base64 -d > /tmp/{$container_name}/docker-compose.yml",
            "docker compose -f /tmp/{$container_name}/docker-compose.yml up -d",

        ], $server);
        return $activity;
    }
}
