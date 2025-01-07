<?php

namespace App\Actions\Database;

use App\Models\StandaloneKeydb;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartKeydb
{
    use AsAction;

    public StandaloneKeydb $database;

    public array $commands = [];

    public string $configuration_dir;

    public function handle(StandaloneKeydb $database)
    {
        $this->database = $database;

        $startCommand = "keydb-server --requirepass {$this->database->keydb_password} --appendonly yes";

        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir().'/'.$container_name;

        $this->commands = [
            "echo 'Starting database.'",
            "mkdir -p $this->configuration_dir",
        ];

        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->database->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();
        $this->add_custom_keydb();

        $docker_compose = [
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'command' => $startCommand,
                    'container_name' => $container_name,
                    'environment' => $environment_variables,
                    'restart' => RESTART_MODE,
                    'networks' => [
                        $this->database->destination->network,
                    ],
                    'labels' => defaultDatabaseLabels($this->database)->toArray(),
                    'healthcheck' => [
                        'test' => "keydb-cli --pass {$this->database->keydb_password} ping",
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
        if (! is_null($this->database->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->database->limits_cpuset);
        }
        if ($this->database->destination->server->isLogDrainEnabled() && $this->database->isLogDrainEnabled()) {
            $docker_compose['services'][$container_name]['logging'] = generate_fluentd_configuration();
        }
        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        }
        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = $persistent_storages;
        }
        if (count($persistent_file_volumes) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = $persistent_file_volumes->map(function ($item) {
                return "$item->fs_path:$item->mount_path";
            })->toArray();
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }
        if (! is_null($this->database->keydb_conf) || ! empty($this->database->keydb_conf)) {
            $docker_compose['services'][$container_name]['volumes'][] = [
                'type' => 'bind',
                'source' => $this->configuration_dir.'/keydb.conf',
                'target' => '/etc/keydb/keydb.conf',
                'read_only' => true,
            ];
            $docker_compose['services'][$container_name]['command'] = "keydb-server /etc/keydb/keydb.conf --requirepass {$this->database->keydb_password} --appendonly yes";
        }

        // Add custom docker run options
        $docker_run_options = convertDockerRunToCompose($this->database->custom_docker_run_options);
        $docker_compose = generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $this->database->destination->network);
        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d | tee $this->configuration_dir/docker-compose.yml > /dev/null";
        $readme = generate_readme_file($this->database->name, now());
        $this->commands[] = "echo '{$readme}' > $this->configuration_dir/README.md";
        $this->commands[] = "echo 'Pulling {$database->image} image.'";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml pull";
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

        if ($environment_variables->filter(fn ($env) => str($env)->contains('REDIS_PASSWORD'))->isEmpty()) {
            $environment_variables->push("REDIS_PASSWORD={$this->database->keydb_password}");
        }

        add_coolify_default_environment_variables($this->database, $environment_variables, $environment_variables);

        return $environment_variables->all();
    }

    private function add_custom_keydb()
    {
        if (is_null($this->database->keydb_conf) || empty($this->database->keydb_conf)) {
            return;
        }
        $filename = 'keydb.conf';
        Storage::disk('local')->put("tmp/keydb.conf_{$this->database->uuid}", $this->database->keydb_conf);
        $path = Storage::path("tmp/keydb.conf_{$this->database->uuid}");
        instant_scp($path, "{$this->configuration_dir}/{$filename}", $this->database->destination->server);
        Storage::disk('local')->delete("tmp/keydb.conf_{$this->database->uuid}");
    }
}
