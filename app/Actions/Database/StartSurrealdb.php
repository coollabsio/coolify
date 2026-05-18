<?php

namespace App\Actions\Database;

use App\Models\StandaloneSurrealdb;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartSurrealdb
{
    use AsAction;

    public StandaloneSurrealdb $database;

    public array $commands = [];

    public string $configuration_dir;

    public function handle(StandaloneSurrealdb $database)
    {
        $this->database = $database;
        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir().'/'.$container_name;
        if (isDev()) {
            $this->configuration_dir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/'.$container_name;
        }

        $this->commands = [
            "echo 'Starting SurrealDB.'",
            "echo 'Creating directories.'",
            "mkdir -p $this->configuration_dir",
            "echo 'Directories created successfully.'",
        ];

        $persistent_storages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();

        $backend = 'surrealkv://data';
        if ($this->database->storage_backend === 'memory') {
            $backend = 'memory';
        } elseif ($this->database->storage_backend === 'file') {
            $backend = 'file://data';
        } elseif ($this->database->storage_backend === 'rocksdb') {
            $backend = 'rocksdb://data';
        } elseif ($this->database->storage_backend === 'tikv') {
            $backend = 'tikv://'.$this->database->tikv_endpoint;
        }

        $command = [
            'start',
            '--user',
            (string) $this->database->surreal_user,
            '--pass',
            (string) $this->database->surreal_password,
            $backend,
        ];

        if ($this->database->surreal_auth !== 'unauthenticated') {
            $command[] = '--auth';
        }

        $docker_compose = [
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'container_name' => $container_name,
                    'command' => $command,
                    'environment' => $environment_variables,
                    'restart' => RESTART_MODE,
                    'networks' => [
                        $this->database->destination->network,
                    ],
                    'labels' => defaultDatabaseLabels($this->database)->toArray(),
                    'healthcheck' => [
                        'test' => ['CMD', 'curl', '-f', 'http://localhost:8000/health'],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 10,
                        'start_period' => '5s',
                    ],
                    'mem_limit' => $this->database->limits_memory,
                    'memswap_limit' => $this->database->limits_memory_swap,
                    'mem_swappiness' => $this->database->limits_memory_swappiness,
                    'mem_reservation' => $this->database->limits_memory_reservation,
                    'cpus' => (float) $this->database->limits_cpus,
                    'cpu_shares' => $this->database->limits_cpu_shares,
                ],
            ],
            'networks' => [
                $this->database->destination->network => [
                    'external' => true,
                    'name' => $this->database->destination->network,
                    'attachable' => true,
                ],
            ],
        ];

        if (filled($this->database->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->database->limits_cpuset);
        }

        if ($this->database->destination->server->isLogDrainEnabled() && $this->database->isLogDrainEnabled()) {
            $docker_compose['services'][$container_name]['logging'] = generate_fluentd_configuration();
        }

        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        } else {
            $docker_compose['services'][$container_name]['ports'] = ['8000:8000'];
        }

        $docker_compose['services'][$container_name]['volumes'] ??= [];

        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                $persistent_storages
            );
        }

        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }

        // Add custom docker run options
        $docker_run_options = convertDockerRunToCompose($this->database->custom_docker_run_options);
        $docker_compose = generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $this->database->destination->network);

        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d | tee $this->configuration_dir/docker-compose.yml > /dev/null";
        $this->commands[] = "echo 'Pulling {$database->image} image.'";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml pull";
        $this->commands[] = "docker stop -t 10 $container_name 2>/dev/null || true";
        $this->commands[] = "docker rm -f $container_name 2>/dev/null || true";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml up -d";
        $this->commands[] = "echo 'Database started.'";

        return remote_process($this->commands, $database->destination->server, callEventOnFinish: 'DatabaseStatusChanged');
    }

    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path !== '' && $persistentStorage->host_path !== null) {
                $local_persistent_volumes[] = $persistentStorage->host_path.':'.$persistentStorage->mount_path;
            } else {
                $volume_name = $persistentStorage->name;
                $local_persistent_volumes[] = $volume_name.':'.$persistentStorage->mount_path;
            }
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

    private function generate_environment_variables()
    {
        $environment_variables = collect();
        foreach ($this->database->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->real_value");
        }

        if ($environment_variables->filter(fn ($env) => str($env)->contains('SURREAL_USER'))->isEmpty()) {
            $environment_variables->push("SURREAL_USER={$this->database->surreal_user}");
        }

        if ($environment_variables->filter(fn ($env) => str($env)->contains('SURREAL_PASS'))->isEmpty()) {
            $environment_variables->push("SURREAL_PASS={$this->database->surreal_password}");
        }

        add_coolify_default_environment_variables($this->database, $environment_variables, $environment_variables);

        return $environment_variables->all();
    }
}
