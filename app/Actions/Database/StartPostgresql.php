<?php

namespace App\Actions\Database;

use App\Actions\Database\Pgbackrest\GeneratePgbackrestConfig;
use App\Helpers\SslHelper;
use App\Models\SslCertificate;
use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartPostgresql
{
    use AsAction;

    public StandalonePostgresql $database;

    public array $commands = [];

    public array $init_scripts = [];

    public string $configuration_dir;

    private ?SslCertificate $ssl_certificate = null;

    private bool $pgbackrest_enabled = false;

    public function handle(StandalonePostgresql $database)
    {
        $this->database = $database;
        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir().'/'.$container_name;

        $this->commands = [
            "echo 'Starting database.'",
            "echo 'Creating directories.'",
            "mkdir -p $this->configuration_dir",
            "mkdir -p $this->configuration_dir/docker-entrypoint-initdb.d/",
            "echo 'Directories created successfully.'",
        ];

        if (! $this->database->enable_ssl) {
            $this->commands[] = "rm -rf $this->configuration_dir/ssl";

            $this->database->sslCertificates()->delete();

            $this->database->fileStorages()
                ->where('resource_type', $this->database->getMorphClass())
                ->where('resource_id', $this->database->id)
                ->get()
                ->filter(function ($storage) {
                    return in_array($storage->mount_path, [
                        '/var/lib/postgresql/certs/server.crt',
                        '/var/lib/postgresql/certs/server.key',
                    ]);
                })
                ->each(function ($storage) {
                    $storage->delete();
                });
        } else {
            $this->commands[] = "echo 'Setting up SSL for this database.'";
            $this->commands[] = "mkdir -p $this->configuration_dir/ssl";

            $server = $this->database->destination->server;
            $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();

            if (! $caCert) {
                $server->generateCaCertificate();
                $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();
            }

            if (! $caCert) {
                $this->dispatch('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');

                return;
            }

            $this->ssl_certificate = $this->database->sslCertificates()->first();

            if (! $this->ssl_certificate) {
                $this->commands[] = "echo 'No SSL certificate found, generating new SSL certificate for this database.'";
                $this->ssl_certificate = SslHelper::generateSslCertificate(
                    commonName: $this->database->uuid,
                    resourceType: $this->database->getMorphClass(),
                    resourceId: $this->database->id,
                    serverId: $server->id,
                    caCert: $caCert->ssl_certificate,
                    caKey: $caCert->ssl_private_key,
                    configurationDir: $this->configuration_dir,
                    mountPath: '/var/lib/postgresql/certs',
                );
            }
        }

        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->database->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();
        $this->generate_init_scripts();
        $this->pgbackrest_enabled = $this->database->isPgbackrestEnabled();
        $this->add_custom_conf();
        $this->setup_pgbackrest();

        $docker_compose = [
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'container_name' => $container_name,
                    'environment' => $environment_variables,
                    'restart' => RESTART_MODE,
                    'networks' => [
                        $this->database->destination->network,
                    ],
                    'labels' => defaultDatabaseLabels($this->database)->toArray(),
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            "psql -U {$this->database->postgres_user} -d {$this->database->postgres_db} -c 'SELECT 1' || exit 1",
                        ],
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

        if (count($this->init_scripts) > 0) {
            foreach ($this->init_scripts as $init_script) {
                $docker_compose['services'][$container_name]['volumes'] = array_merge(
                    $docker_compose['services'][$container_name]['volumes'],
                    [[
                        'type' => 'bind',
                        'source' => $this->getHostPath($init_script),
                        'target' => '/docker-entrypoint-initdb.d/'.basename($init_script),
                        'read_only' => true,
                    ]]
                );
            }
        }

        $command = ['postgres'];

        if (filled($this->database->postgres_conf) || $this->pgbackrest_enabled) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                [[
                    'type' => 'bind',
                    'source' => $this->getHostPath($this->configuration_dir).'/custom-postgres.conf',
                    'target' => '/etc/postgresql/postgresql.conf',
                    'read_only' => true,
                ]]
            );
            $command = array_merge($command, ['-c', 'config_file=/etc/postgresql/postgresql.conf']);
        }

        if ($this->database->enable_ssl) {
            $command = array_merge($command, [
                '-c', 'ssl=on',
                '-c', 'ssl_cert_file=/var/lib/postgresql/certs/server.crt',
                '-c', 'ssl_key_file=/var/lib/postgresql/certs/server.key',
            ]);
        }

        if ($this->pgbackrest_enabled) {
            $docker_compose = $this->add_pgbackrest_to_postgres($docker_compose, $container_name);
        }

        $docker_run_options = convertDockerRunToCompose($this->database->custom_docker_run_options);
        $docker_compose = generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $this->database->destination->network);

        if (count($command) > 1) {
            $docker_compose['services'][$container_name]['command'] = $command;
        }

        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d | tee $this->configuration_dir/docker-compose.yml > /dev/null";
        $readme = generate_readme_file($this->database->name, now());
        $this->commands[] = "echo '{$readme}' > $this->configuration_dir/README.md";
        $this->commands[] = "echo 'Pulling {$database->image} image.'";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml pull";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml down --remove-orphans 2>/dev/null || true";
        $this->commands[] = "docker stop -t 10 $container_name 2>/dev/null || true";
        $this->commands[] = "docker rm -f $container_name 2>/dev/null || true";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml up -d";
        if ($this->database->enable_ssl) {
            $this->commands[] = executeInDocker($this->database->uuid, "chown {$this->database->postgres_user}:{$this->database->postgres_user} /var/lib/postgresql/certs/server.key /var/lib/postgresql/certs/server.crt");
        }

        if ($this->pgbackrest_enabled) {
            $this->add_stanza_creation_commands($container_name);
        }

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

        if ($environment_variables->filter(fn ($env) => str($env)->contains('POSTGRES_USER'))->isEmpty()) {
            $environment_variables->push("POSTGRES_USER={$this->database->postgres_user}");
        }
        if ($environment_variables->filter(fn ($env) => str($env)->contains('PGUSER'))->isEmpty()) {
            $environment_variables->push("PGUSER={$this->database->postgres_user}");
        }

        if ($environment_variables->filter(fn ($env) => str($env)->contains('POSTGRES_PASSWORD'))->isEmpty()) {
            $environment_variables->push("POSTGRES_PASSWORD={$this->database->postgres_password}");
        }

        if ($environment_variables->filter(fn ($env) => str($env)->contains('POSTGRES_DB'))->isEmpty()) {
            $environment_variables->push("POSTGRES_DB={$this->database->postgres_db}");
        }

        add_coolify_default_environment_variables($this->database, $environment_variables, $environment_variables);

        return $environment_variables->all();
    }

    private function generate_init_scripts()
    {
        $this->commands[] = "rm -rf $this->configuration_dir/docker-entrypoint-initdb.d/*";

        if (blank($this->database->init_scripts) || count($this->database->init_scripts) === 0) {
            return;
        }

        foreach ($this->database->init_scripts as $init_script) {
            $filename = data_get($init_script, 'filename');
            $content = data_get($init_script, 'content');
            $content_base64 = base64_encode($content);
            $this->commands[] = "echo '{$content_base64}' | base64 -d | tee $this->configuration_dir/docker-entrypoint-initdb.d/{$filename} > /dev/null";
            $this->init_scripts[] = "$this->configuration_dir/docker-entrypoint-initdb.d/{$filename}";
        }
    }

    private function add_custom_conf()
    {
        $filename = 'custom-postgres.conf';
        $config_file_path = "$this->configuration_dir/$filename";

        $content = $this->database->postgres_conf ?? '';

        if (! str($content)->contains('listen_addresses')) {
            $content .= "\nlisten_addresses = '*'";
        }

        $archiveConfig = (new GeneratePgbackrestConfig)->generatePostgresConfig($this->database);

        if ($this->pgbackrest_enabled) {
            foreach ($archiveConfig as $key => $value) {
                $pattern = "/^{$key}\s*=.*$/m";
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, "{$key} = '{$value}'", $content);
                } else {
                    $content .= "\n{$key} = '{$value}'";
                }
            }
        } else {
            foreach (array_keys($archiveConfig) as $key) {
                $pattern = "/^{$key}\s*=.*$/m";
                $content = preg_replace($pattern, '', $content);
            }
            $content = preg_replace("/\n{2,}/", "\n", $content);
        }

        $content = trim($content);

        if (blank($content)) {
            $this->commands[] = "rm -f $config_file_path";

            return;
        }

        $this->database->postgres_conf = $content;
        $this->database->save();

        $content_base64 = base64_encode($content);
        $this->commands[] = "echo '{$content_base64}' | base64 -d | tee $config_file_path > /dev/null";
    }

    private function setup_pgbackrest(): void
    {
        $pgbackrestConfigDir = $this->configuration_dir.'/pgbackrest';
        $pgbackrestRepoDir = $this->configuration_dir.'/pgbackrest-repo';

        if (! $this->pgbackrest_enabled) {
            $this->commands[] = "rm -rf {$pgbackrestConfigDir}";

            return;
        }

        $this->commands[] = "mkdir -p {$pgbackrestConfigDir}";
        $this->commands[] = "mkdir -p {$pgbackrestRepoDir}";
        $this->commands[] = "mkdir -p {$pgbackrestRepoDir}/log";

        $config = GeneratePgbackrestConfig::run($this->database);
        $configBase64 = base64_encode($config);
        $this->commands[] = "echo '{$configBase64}' | base64 -d | tee {$pgbackrestConfigDir}/pgbackrest.conf > /dev/null";

        $stanzaName = $this->database->getPgbackrestStanzaName();
        $installScript = $this->generatePgbackrestInstallScript($stanzaName);
        $installScriptBase64 = base64_encode($installScript);
        $this->commands[] = "echo '{$installScriptBase64}' | base64 -d | tee {$pgbackrestConfigDir}/install-pgbackrest.sh > /dev/null";
        $this->commands[] = "chmod +x {$pgbackrestConfigDir}/install-pgbackrest.sh";
    }

    private function generatePgbackrestInstallScript(string $stanzaName): string
    {
        return <<<BASH
#!/bin/bash
set -e

mkdir -p /tmp/pgbackrest
mkdir -p /var/lib/pgbackrest/log

NEED_INSTALL=0
if ! command -v pgbackrest &> /dev/null; then
    NEED_INSTALL=1
fi

if [ "\$NEED_INSTALL" = "1" ]; then
    if [ -f /etc/alpine-release ]; then
        apk add --no-cache pgbackrest
    elif [ -f /etc/debian_version ]; then
        apt-get update && apt-get install -y pgbackrest && rm -rf /var/lib/apt/lists/*
    else
        exit 1
    fi
fi

# Fix permissions for postgres user
chown -R postgres:postgres /tmp/pgbackrest /var/lib/pgbackrest /etc/pgbackrest 2>/dev/null || true
chmod -R 770 /tmp/pgbackrest /var/lib/pgbackrest 2>/dev/null || true

# Create stanza if it doesn't exist (before PostgreSQL starts archiving)
if [ -d /var/lib/postgresql/data ] && [ -f /var/lib/postgresql/data/PG_VERSION ]; then
    STANZA_CHECK=\$(su postgres -c "pgbackrest --stanza={$stanzaName} info" 2>&1 || true)
    if echo "\$STANZA_CHECK" | grep -q 'missing stanza'; then
        echo "Creating pgbackrest stanza..."
        su postgres -c "pgbackrest --stanza={$stanzaName} stanza-create"
    fi
fi
BASH;
    }

    private function add_pgbackrest_to_postgres(array $docker_compose, string $postgres_container): array
    {
        $pgbackrestConfigDir = $this->configuration_dir.'/pgbackrest';
        $pgbackrestRepoDir = $this->configuration_dir.'/pgbackrest-repo';

        $hostPgbackrestConfigDir = $this->getHostPath($pgbackrestConfigDir);
        $hostPgbackrestRepoDir = $this->getHostPath($pgbackrestRepoDir);

        $docker_compose['services'][$postgres_container]['volumes'][] = [
            'type' => 'bind',
            'source' => $hostPgbackrestConfigDir,
            'target' => '/etc/pgbackrest',
        ];

        $docker_compose['services'][$postgres_container]['volumes'][] = [
            'type' => 'bind',
            'source' => $hostPgbackrestRepoDir,
            'target' => '/var/lib/pgbackrest',
        ];

        $docker_compose['services'][$postgres_container]['entrypoint'] = [
            '/bin/sh',
            '-c',
            '/etc/pgbackrest/install-pgbackrest.sh && exec docker-entrypoint.sh "$@"',
            '--',
        ];

        return $docker_compose;
    }

    /**
     * Convert a path from the SSH target perspective to the Docker host perspective.
     *
     * In dev mode, SSH commands run in coolify-testing-host where the volume is mounted
     * at /data/coolify, but Docker Compose runs on the host where the same volume is at
     * /var/lib/docker/volumes/coolify_dev_coolify_data/_data.
     */
    private function getHostPath(string $path): string
    {
        if (isDev()) {
            return str_replace('/data/coolify', '/var/lib/docker/volumes/coolify_dev_coolify_data/_data', $path);
        }

        return $path;
    }

    private function add_pgbackrest_permission_fix_commands(string $container_name): void
    {
        $this->commands[] = "docker exec -u 0:0 {$container_name} sh -c 'chown -R postgres:postgres /var/lib/pgbackrest /etc/pgbackrest /tmp/pgbackrest 2>/dev/null || true; chmod -R 770 /var/lib/pgbackrest /etc/pgbackrest /tmp/pgbackrest 2>/dev/null || true'";
    }

    private function add_stanza_creation_commands(string $container_name): void
    {
        $stanzaName = $this->database->getPgbackrestStanzaName();

        $user = escapeshellarg($this->database->postgres_user);
        $db = escapeshellarg($this->database->postgres_db);
        $this->commands[] = "until docker exec {$container_name} pg_isready -U {$user} -d {$db} > /dev/null 2>&1; do sleep 2; done";
        $this->commands[] = "STANZA_CHECK=\$(docker exec {$container_name} su postgres -c 'pgbackrest --stanza={$stanzaName} info' 2>&1 || true)";
        $this->commands[] = "if echo \"\$STANZA_CHECK\" | grep -q 'missing stanza'; then docker exec {$container_name} su postgres -c 'pgbackrest --stanza={$stanzaName} stanza-create'; elif echo \"\$STANZA_CHECK\" | grep -q 'stanza version'; then docker exec {$container_name} su postgres -c 'pgbackrest --stanza={$stanzaName} stanza-upgrade' 2>/dev/null || true; fi";
    }
}
