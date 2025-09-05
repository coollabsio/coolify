<?php

namespace App\Actions\Database;

use App\Helpers\SslHelper;
use App\Models\SslCertificate;
use App\Models\StandaloneLibsql;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartLibsql
{
    use AsAction;

    public StandaloneLibsql $database;

    public array $commands = [];

    public string $configuration_dir;

    private ?SslCertificate $ssl_certificate = null;

    public function handle(StandaloneLibsql $database)
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

        $startCommand = $this->buildStartCommand();

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
                        'test' => [
                            'CMD',
                            "curl",
                            "-f",
                            "http://0.0.0.0:8080/health",
                        ],
                        'interval' => '10s',
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

        if (filled($this->database->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->database->limits_cpuset);
        }

        if ($this->database->destination->server->isLogDrainEnabled() && $this->database->isLogDrainEnabled()) {
            $docker_compose['services'][$container_name]['logging'] = generate_fluentd_configuration();
        }

        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        }

        $docker_compose['services'][$container_name]['volumes'] ??= [];

        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                $persistent_storages
            );
        }

        if (count($persistent_file_volumes) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                $persistent_file_volumes->map(function ($item) {
                    return "$item->fs_path:$item->mount_path";
                })->toArray()
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

        $environment_variables->push("SQLD_NODE={$this->database->sqld_node}");

        // Libsql specific environment variables
        if ($this->database->sqld_node === 'replica' && $this->database->sqld_primary_url) {
            $environment_variables->push("SQLD_PRIMARY_URL={$this->database->sqld_primary_url}");
        }

        if ($this->database->sqld_http_auth_user && $this->database->sqld_http_auth_password) {
            $authString = $this->database->sqld_http_auth_user . ':' . $this->database->sqld_http_auth_password;
            $encoded = base64_encode($authString);

            $environment_variables->push("SQLD_HTTP_AUTH=basic:{$encoded}");
        }

        if ($this->database->sqld_auth_jwt_key) {
            $environment_variables->push("SQLD_AUTH_JWT_KEY={$this->database->sqld_auth_jwt_key}");
        }

        if ($this->database->sqld_http_port) {
            $environment_variables->push("SQLD_HTTP_LISTEN_ADDR=0.0.0.0:{$this->database->sqld_http_port}");
        }

        if ($this->database->sqld_grpc_port && $this->database->sqld_node === 'primary') {
            $environment_variables->push("SQLD_GRPC_LISTEN_ADDR=0.0.0.0:{$this->database->sqld_grpc_port}");
        }



        if ($this->database->enable_bottomless_replication && $this->database->s3_bucket) {
            $environment_variables->push("LIBSQL_BOTTOMLESS_REPLICATION=1");
            $environment_variables->push("LIBSQL_S3_BUCKET={$this->database->s3_bucket}");

            if ($this->database->s3_region) {
                $environment_variables->push("LIBSQL_S3_REGION={$this->database->s3_region}");
            }

            if ($this->database->s3_access_key) {
                $environment_variables->push("LIBSQL_S3_ACCESS_KEY_ID={$this->database->s3_access_key}");
            }

            if ($this->database->s3_secret_key) {
                $environment_variables->push("LIBSQL_S3_SECRET_ACCESS_KEY={$this->database->s3_secret_key}");
            }

            if ($this->database->s3_endpoint) {
                $environment_variables->push("LIBSQL_S3_ENDPOINT={$this->database->s3_endpoint}");
            }
        }

        add_coolify_default_environment_variables($this->database, $environment_variables, $environment_variables);

        return $environment_variables->all();
    }

    private function buildStartCommand(): string
    {
        $command = "sqld --enable-namespaces";

        return $command;
    }
}
