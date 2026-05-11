<?php

namespace App\Actions\Database;

use App\Models\StandaloneSurrealDB;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartSurrealDB
{
    use AsAction;

    public StandaloneSurrealDB $database;

    public array $commands = [];

    public string $configuration_dir;

    public function handle(StandaloneSurrealDB $database)
    {
        $this->database = $database;

        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir().'/'.$container_name;

        $this->commands = [
            "echo 'Starting database.'",
            "echo 'Creating directories.'",
            "mkdir -p $this->configuration_dir",
            "echo 'Directories created successfully.'",
        ];

        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->database->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();

        $docker_compose = [
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'command' => 'start --bind 0.0.0.0:8000 --user '.$this->database->surrealdb_user.' --pass '.$this->database->surrealdb_password.' file:///data/surrealdb.db',
                    'container_name' => $container_name,
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
                        'start_period' => '10s',
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

        if (! is_null($this->database->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->database->limits_cpuset);
        }

        // log drain
        if ($this->database->destination->server->isLogDrainEnabled() && $this->database->isLogDrainEnabled()) {
            $docker_compose['services'][$container_name]['logging'] = generate_fluentd_configuration();
        }

        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        }

        $docker_compose['services'][$container_name]['volumes'] ??= [];

        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'] ?? [],
                $persistent_storages
            );
        }

        if (count($persistent_file_volumes) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'] ?? [],
                $persistent_file_volumes->map(fn ($item) => "$item->fs_path:$item->mount_path")->toArray()
            );
        }

        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }

        $docker_run_options = convertDockerRunToCompose($this->database->custom_docker_run_options);
        $docker_compose = generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $this->database->destination->network);
        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d | tee $this->configuration_dir/docker-compose.yml > /dev/null";
        $readme = generate_readme_file($this->database->name, now());
        $this->commands[] = "echo '{$readme}' > $this->configuration_dir/README.md";
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
        $volumes = [];
        foreach ($this->database->persistentStorages as $storage) {
            if ($storage->host_path !== '' && $storage->host_path !== null) {
                $volumes[] = $storage->host_path.':'.$storage->mount_path;
            } else {
                $volumes[] = $storage->name.':'.$storage->mount_path;
            }
        }

        return $volumes;
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        $names = [];
        foreach ($this->database->persistentStorages as $storage) {
            if ($storage->host_path) {
                continue;
            }
            $names[$storage->name] = [
                'name' => $storage->name,
                'external' => false,
            ];
        }

        return $names;
    }

    private function generate_environment_variables()
    {
        $envs = collect();
        foreach ($this->database->runtime_environment_variables as $env) {
            $envs->push("$env->key=$env->real_value");
        }

        add_coolify_default_environment_variables($this->database, $envs, $envs);

        return $envs->all();
    }
}
