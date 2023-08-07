<?php

namespace App\Actions\Database;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\StandalonePostgresql;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Str;

class StartPostgresql
{
    public $database;
    public function __invoke(Server $server, StandalonePostgresql $database)
    {
        $this->database = $database;

        $container_name = generate_container_name($this->database->uuid);
        $destination = $this->database->destination;
        $image = $this->database->image;

        $persistent_storages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();

        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $container_name => [
                    'image' => $image,
                    'container_name' => $container_name,
                    'environment' => $environment_variables,
                    'restart' => 'always',
                    'networks' => [
                        $destination->network,
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            'pg_isready',
                            '-d',
                            $this->database->postgres_db,
                            '-U',
                            $this->database->postgres_user,
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 10,
                        'start_period' => '5s'
                    ],
                    'mem_limit' => $this->database->limits_memory,
                    'memswap_limit' => $this->database->limits_memory_swap,
                    'mem_swappiness' => $this->database->limits_memory_swappiness,
                    'mem_reservation' => $this->database->limits_memory_reservation,
                    'cpus' => $this->database->limits_cpus,
                    'cpuset' => $this->database->limits_cpuset,
                    'cpu_shares' => $this->database->limits_cpu_shares,
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
        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = $persistent_storages;
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }

        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $activity = remote_process([
            "mkdir -p /tmp/{$container_name}",
            "echo '{$docker_compose_base64}' | base64 -d > /tmp/{$container_name}/docker-compose.yml",
            "docker compose -f /tmp/{$container_name}/docker-compose.yml up -d",

        ], $server);
        return $activity;
    }
    private function generate_environment_variables()
    {
        $environment_variables = collect();
        ray('Generate Environment Variables')->green();
        ray($this->database->runtime_environment_variables)->green();
        foreach ($this->database->runtime_environment_variables as $env) {
                $environment_variables->push("$env->key=$env->value");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('POSTGRES_USER'))->isEmpty()) {
            $environment_variables->push("POSTGRES_USER={$this->database->postgres_user}");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('POSTGRES_PASSWORD'))->isEmpty()) {
            $environment_variables->push("POSTGRES_PASSWORD={$this->database->postgres_password}");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('POSTGRES_DB'))->isEmpty()) {
            $environment_variables->push("POSTGRES_DB={$this->database->postgres_db}");
        }
        return $environment_variables->all();
    }
    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            $volume_name = $persistentStorage->host_path ?? $persistentStorage->name;
            $local_persistent_volumes[] = $volume_name . ':' . $persistentStorage->mount_path;
        }
        return $local_persistent_volumes;
    }
    private function generate_local_persistent_volumes_only_volume_names()
    {
        $local_persistent_volumes_names = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;
            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }
        return $local_persistent_volumes_names;
    }
}
