<?php

namespace App\Services\ProjectConfig\Adapters;

use App\Actions\Application\LookupGitAppRepository;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\ProjectConfig\Adapters\Concerns\MapsFields;
use App\Services\ProjectConfig\Adapters\Concerns\ResolvesDestination;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Children\AdditionalServers;
use App\Services\ProjectConfig\Children\ChildRecords;
use App\Services\ProjectConfig\Children\EnvironmentVariables;
use App\Services\ProjectConfig\Children\FileStorages;
use App\Services\ProjectConfig\Children\PersistentStorages;
use App\Services\ProjectConfig\Children\ScheduledTasks;
use App\Services\ProjectConfig\Children\SecretManager;
use App\Services\ProjectConfig\Children\Tags;
use App\Services\ProjectConfig\ConfigError;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use App\Services\ProjectConfig\FieldValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use LogicException;

class ApplicationAdapter implements ResourceAdapter
{
    use MapsFields;
    use ResolvesDestination;

    public const SOURCE_KINDS = [
        'github_app' => GithubApp::class,
        'gitlab_app' => GitlabApp::class,
        'gitea_app' => GiteaApp::class,
    ];

    private const SETTINGS = 'application_settings';

    private ?FieldMap $map = null;

    /** @var list<ChildRecords>|null */
    private ?array $children = null;

    public function type(): string
    {
        return 'application';
    }

    public function modelClass(): string
    {
        return Application::class;
    }

    protected function customPaths(): array
    {
        return ['name', 'server', 'destination', 'source.app', 'source.deploy_key', 'domains', 'labels'];
    }

    public function fieldMap(): FieldMap
    {
        return $this->map ??= new FieldMap('applications', [
            Field::managed('name', 'name'),
            Field::managed('description', 'description'),
            Field::managed('destination_type', 'destination'),
            Field::managed('destination_id', 'destination'),
            Field::managed('fqdn', 'domains', jsonType: 'array'),
            Field::managed('noindex_domains', 'noindex_domains', jsonType: 'array'),
            Field::managed('redirect', 'redirect', 'both'),
            Field::managed('domain_port_overrides', 'domain_port_overrides', jsonType: 'object'),
            Field::managed('git_repository', 'source.repository'),
            Field::managed('git_branch', 'source.branch'),
            Field::managed('git_commit_sha', 'source.commit', 'HEAD'),
            Field::managed('source_type', 'source.app'),
            Field::managed('source_id', 'source.app'),
            Field::managed('private_key_id', 'source.deploy_key'),
            Field::managed('git_https_username', 'source.https_username'),
            Field::managed('git_ssh_known_hosts', 'source.ssh_known_hosts'),
            Field::derived('repository_project_id', 'Coolify looks it up from the git source and the repository.'),
            Field::derived('git_full_url', 'Coolify builds it from the repository and the git source.'),
            Field::secret('git_https_password', 'A git password is a credential. Set it in Coolify.'),
            Field::managed('docker_registry_image_name', 'image.name'),
            Field::managed('docker_registry_image_tag', 'image.tag'),
            Field::managed('build_pack', 'build.pack'),
            Field::managed('static_image', 'build.static_image', 'nginx:alpine'),
            Field::managed('install_command', 'build.install_command'),
            Field::managed('build_command', 'build.build_command'),
            Field::managed('start_command', 'build.start_command'),
            Field::managed('base_directory', 'build.base_directory', '/'),
            Field::managed('publish_directory', 'build.publish_directory', '/'),
            Field::managed('dockerfile', 'build.dockerfile'),
            Field::managed('dockerfile_location', 'build.dockerfile_location'),
            Field::managed('dockerfile_target_build', 'build.dockerfile_target'),
            Field::managed('custom_nginx_configuration', 'build.nginx_configuration'),
            Field::managed('watch_paths', 'build.watch_paths', jsonType: 'array', format: FieldValues::LINES),
            Field::managed('docker_compose_location', 'build.compose_location', '/docker-compose.yaml'),
            Field::managed('docker_compose_custom_start_command', 'build.compose_start_command'),
            Field::managed('docker_compose_custom_build_command', 'build.compose_build_command'),
            Field::managed('docker_compose_domains', 'compose.domains', jsonType: 'object', format: FieldValues::JSON_TEXT),
            Field::managed('docker_compose_image_overrides', 'compose.image_overrides', jsonType: 'object'),
            Field::derived('docker_compose_raw', 'Coolify loads it from the compose file in the repository.'),
            Field::derived('docker_compose', 'Coolify generates it from the compose file.'),
            Field::derived('compose_parsing_version', 'Coolify sets the parser version when it creates the application.'),
            Field::derived('custom_healthcheck_found', 'Coolify finds it in the Dockerfile.'),
            Field::managed('ports_exposes', 'ports.exposes'),
            Field::managed('ports_mappings', 'ports.mappings'),
            Field::managed('custom_network_aliases', 'network.aliases'),
            Field::managed('health_check_enabled', 'health_check.enabled', false, 'boolean'),
            Field::managed('health_check_type', 'health_check.type', 'http'),
            Field::managed('health_check_command', 'health_check.command'),
            Field::managed('health_check_path', 'health_check.path', '/'),
            Field::managed('health_check_port', 'health_check.port'),
            Field::managed('health_check_host', 'health_check.host', 'localhost'),
            Field::managed('health_check_method', 'health_check.method', 'GET'),
            Field::managed('health_check_return_code', 'health_check.return_code', 200, 'integer'),
            Field::managed('health_check_scheme', 'health_check.scheme', 'http'),
            Field::managed('health_check_response_text', 'health_check.response_text'),
            Field::managed('health_check_interval', 'health_check.interval', 5, 'integer'),
            Field::managed('health_check_timeout', 'health_check.timeout', 5, 'integer'),
            Field::managed('health_check_retries', 'health_check.retries', 10, 'integer'),
            Field::managed('health_check_start_period', 'health_check.start_period', 5, 'integer'),
            Field::managed('limits_memory', 'limits.memory', '0'),
            Field::managed('limits_memory_swap', 'limits.memory_swap', '0'),
            Field::managed('limits_memory_swappiness', 'limits.memory_swappiness', 60, 'integer'),
            Field::managed('limits_memory_reservation', 'limits.memory_reservation', '0'),
            Field::managed('limits_cpus', 'limits.cpus', '0'),
            Field::managed('limits_cpuset', 'limits.cpuset'),
            Field::managed('limits_cpu_shares', 'limits.cpu_shares', 1024, 'integer'),
            Field::managed('custom_labels', 'labels'),
            Field::managed('custom_docker_run_options', 'docker_run_options'),
            Field::managed('pre_deployment_command', 'deployment.pre_command'),
            Field::managed('pre_deployment_command_container', 'deployment.pre_command_container'),
            Field::managed('post_deployment_command', 'deployment.post_command'),
            Field::managed('post_deployment_command_container', 'deployment.post_command_container'),
            Field::managed('preview_url_template', 'preview.url_template', '{{pr_id}}.{{domain}}'),
            Field::managed('swarm_replicas', 'swarm.replicas', 1, 'integer'),
            Field::managed('swarm_placement_constraints', 'swarm.placement_constraints', format: FieldValues::BASE64),
            Field::managed('is_http_basic_auth_enabled', 'basic_auth.enabled', false, 'boolean'),
            Field::managed('http_basic_auth_username', 'basic_auth.username'),
            Field::secret('http_basic_auth_password', 'A password is a credential. Set it in Coolify.'),
            Field::managed('http_basic_auth_hash_algorithm', 'basic_auth.hash_algorithm', 'bcrypt'),
            Field::managed('http_basic_auth_bcrypt_cost', 'basic_auth.bcrypt_cost', 10, 'integer'),
            Field::managed('http_basic_auth_argon2id_memory_cost', 'basic_auth.argon2id_memory_cost', 65536, 'integer'),
            Field::managed('http_basic_auth_argon2id_time_cost', 'basic_auth.argon2id_time_cost', 4, 'integer'),
            Field::managed('max_restart_count', 'restart.max_count', 0, 'integer'),
            Field::managed('is_maintenance_enabled', 'maintenance.enabled', false, 'boolean'),
            Field::managed('maintenance_page', 'maintenance.page'),
            Field::secret('manual_webhook_secret_github', 'A webhook secret is a credential. Set it in Coolify.'),
            Field::secret('manual_webhook_secret_gitlab', 'A webhook secret is a credential. Set it in Coolify.'),
            Field::secret('manual_webhook_secret_bitbucket', 'A webhook secret is a credential. Set it in Coolify.'),
            Field::secret('manual_webhook_secret_gitea', 'A webhook secret is a credential. Set it in Coolify.'),
            Field::state('config_hash', 'Coolify stores the hash of the last deployed configuration.'),
            Field::state('status', 'Runtime status of the containers.'),
            Field::state('last_online_at', 'Runtime status of the containers.'),
            Field::state('restart_count', 'Runtime restart counter.'),
            Field::state('last_restart_at', 'Runtime restart counter.'),
            Field::state('last_restart_type', 'Runtime restart counter.'),
            Field::state('restart_limit_reached', 'Runtime restart counter.'),
            Field::state('container_present', 'Runtime status of the containers.'),
            Field::state('domain_dns_statuses', 'Result of the last DNS check.'),
            ...$this->settingsFields(),
        ], [
            'applications' => ['environment_id'],
            self::SETTINGS => ['application_id'],
        ]);
    }

    /**
     * @return list<Field>
     */
    private function settingsFields(): array
    {
        $booleans = [
            'is_static' => false, 'is_git_submodules_enabled' => true, 'is_git_lfs_enabled' => true, 'is_auto_deploy_enabled' => true,
            'is_force_https_enabled' => true, 'is_debug_enabled' => false, 'is_preview_deployments_enabled' => false,
            'is_log_drain_enabled' => false, 'is_gpu_enabled' => false, 'is_include_timestamps' => false,
            'is_swarm_only_worker_nodes' => true, 'is_raw_compose_deployment_enabled' => false, 'is_build_server_enabled' => false,
            'is_consistent_container_name_enabled' => false, 'is_gzip_enabled' => true, 'is_stripprefix_enabled' => true,
            'connect_to_docker_network' => false, 'is_container_label_escape_enabled' => true, 'is_env_sorting_enabled' => false,
            'is_container_label_readonly_enabled' => true, 'is_preserve_repository_enabled' => false, 'disable_build_cache' => false,
            'is_spa' => false, 'is_git_shallow_clone_enabled' => true, 'is_pr_deployments_public_enabled' => false,
            'use_build_secrets' => false, 'inject_build_args_to_dockerfile' => true, 'include_source_commit_in_build' => false,
            'redact_all_env_values_in_logs' => true, 'include_coolify_default_variables' => true, 'is_permanent_redirect_enabled' => false,
            'is_image_scan_enabled' => true, 'is_auto_cancel_queued_deployments_enabled' => true,
            'is_auto_cancel_building_deployments_enabled' => false, 'include_network_hosts_in_build' => true,
        ];
        $fields = [];
        foreach ($booleans as $column => $default) {
            $fields[] = Field::managed($column, "settings.{$column}", $default, 'boolean', self::SETTINGS);
        }

        return [
            ...$fields,
            Field::managed('image_builds_by_team', 'settings.image_builds_by_team', null, 'boolean', self::SETTINGS),
            Field::managed('gpu_driver', 'settings.gpu_driver', 'nvidia', 'string', self::SETTINGS),
            Field::managed('gpu_count', 'settings.gpu_count', null, 'string', self::SETTINGS),
            Field::managed('gpu_device_ids', 'settings.gpu_device_ids', null, 'string', self::SETTINGS),
            Field::managed('gpu_options', 'settings.gpu_options', null, 'string', self::SETTINGS),
            Field::managed('custom_internal_name', 'settings.custom_internal_name', null, 'string', self::SETTINGS),
            Field::managed('docker_images_to_keep', 'settings.docker_images_to_keep', 2, 'integer', self::SETTINGS),
            Field::managed('stop_grace_period', 'settings.stop_grace_period', null, 'integer', self::SETTINGS),
            Field::managed('custom_container_name_prefix', 'settings.custom_container_name_prefix', null, 'string', self::SETTINGS),
            Field::managed('traefik_certificate_resolver', 'settings.traefik_certificate_resolver', null, 'string', self::SETTINGS),
            Field::managed('rolling_update_drain_seconds', 'settings.rolling_update_drain_seconds', 5, 'integer', self::SETTINGS),
            Field::state('has_unlinked_config_file', 'Coolify sets it when a deployment finds a coolify.json that no link uses.', self::SETTINGS),
        ];
    }

    /**
     * @return list<ChildRecords>
     */
    public function children(): array
    {
        return $this->children ??= [
            new EnvironmentVariables,
            new PersistentStorages,
            new FileStorages,
            new ScheduledTasks,
            new Tags,
            new SecretManager,
            new AdditionalServers,
        ];
    }

    public function childTables(): array
    {
        $maps = [];
        foreach ($this->children() as $child) {
            if ($child->map() !== null) {
                $maps[$child->key()] = $child->map();
            }
        }

        return $maps;
    }

    public function schema(): array
    {
        $properties = $this->mappedFieldsSchema($this->fieldMap());
        $properties['type'] = ['const' => 'application'];
        $properties['name'] = ['type' => 'string'];
        $properties['server'] = ['type' => 'string'];
        $properties['destination'] = ['type' => 'string'];
        $properties['domains'] = ['type' => 'array', 'items' => ['type' => 'string']];
        $properties['labels'] = ['type' => ['string', 'null']];
        $properties['source']['properties'] += [
            'github_app' => ['type' => 'string'],
            'gitlab_app' => ['type' => 'string'],
            'gitea_app' => ['type' => 'string'],
            'deploy_key' => ['type' => 'string'],
        ];
        foreach ($this->children() as $child) {
            $properties[$child->key()] = $child->schema();
        }
        ksort($properties);

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
    }

    public function find(Environment $environment, string $key): ?Model
    {
        return Application::query()->where('environment_id', $environment->id)->where('config_key', $key)->first();
    }

    public function findByUuid(Environment $environment, string $uuid): ?Model
    {
        return Application::query()->where('environment_id', $environment->id)->where('uuid', $uuid)->where('id', '!=', 0)->first();
    }

    public function unkeyed(Environment $environment): Collection
    {
        return Application::query()->where('environment_id', $environment->id)->whereNull('config_key')->where('id', '!=', 0)->orderBy('id')->get();
    }

    public function canonical(string $key, array $desired): array
    {
        $desired['name'] ??= $key;
        if (isset($desired['domains'])) {
            $desired['domains'] = array_values(array_map(FieldValues::normalizeDomain(...), (array) $desired['domains']));
            if ($desired['domains'] === []) {
                unset($desired['domains']);
            }
        }
        $desired = $this->dropMappedDefaults($this->fieldMap(), $desired);
        foreach ($this->children() as $child) {
            $value = $child->canonical($desired[$child->key()] ?? null);
            unset($desired[$child->key()]);
            if ($value !== null) {
                $desired[$child->key()] = $value;
            }
        }

        return $this->ordered($desired);
    }

    public function read(Model $resource, ApplyContext $ctx): array
    {
        /** @var Application $resource */
        $result = ['type' => 'application', 'name' => $resource->name];
        $result += $this->readMappedFields($this->fieldMap(), ['applications' => $resource, self::SETTINGS => $resource->settings]);

        $destination = $resource->destination;
        $server = $destination?->server;
        if ($server !== null) {
            $result['server'] = $server->name;
            if ($server->destinations()->count() > 1) {
                $result['destination'] = $destination->network;
            }
        }

        $source = $this->readSource($resource);
        if ($source !== []) {
            $result['source'] = array_merge($result['source'] ?? [], $source);
        }

        $domains = FieldValues::domainList($resource->fqdn);
        if ($domains !== []) {
            $result['domains'] = $domains;
        }

        foreach ($this->children() as $child) {
            $value = $child->read($resource);
            if ($value !== null) {
                $result[$child->key()] = $value;
            }
        }

        if (! ($resource->settings?->is_container_label_readonly_enabled ?? true) && filled($resource->custom_labels)) {
            $result['labels'] = FieldValues::toFile(new Field('custom_labels', $this->fieldMap()->forPath('labels')->class, format: FieldValues::BASE64), $resource->custom_labels);
        }

        return $this->ordered($result);
    }

    /**
     * @return array<string, string>
     */
    private function readSource(Application $application): array
    {
        if ($application->private_key_id !== null && $application->source_type === null) {
            $key = PrivateKey::query()->find($application->private_key_id);

            return $key ? ['deploy_key' => $key->name] : [];
        }

        $kind = array_search($application->source_type, self::SOURCE_KINDS, true);
        if ($kind === false || $application->source_id === null) {
            return [];
        }
        $source = $application->source;
        if ($source === null || ($kind === 'github_app' && (int) $source->id === 0)) {
            return [];
        }

        return [$kind => $source->name];
    }

    /**
     * Keeps a stable key order so that equal states encode to equal JSON.
     */
    private function ordered(array $value): array
    {
        if (array_is_list($value)) {
            return $value;
        }
        if (($value['build']['pack'] ?? null) === 'dockerfile' && ($value['build']['dockerfile_location'] ?? null) === '/Dockerfile') {
            unset($value['build']['dockerfile_location']);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item) && in_array($key, ['source', 'build', 'image', 'ports', 'network', 'health_check', 'limits', 'deployment', 'preview', 'swarm', 'basic_auth', 'restart', 'maintenance', 'settings', 'compose'], true)) {
                $value[$key] = $this->ordered($item);
            }
        }

        return $value;
    }

    public function validate(string $key, array $desired, ApplyContext $ctx, ?Model $current = null): array
    {
        $base = "resources.{$key}";
        $errors = [];

        if (! isset($desired['build']['pack'])) {
            $errors[] = new ConfigError("{$base}.build.pack", 'Add the build pack.');
        }

        $server = $this->server($desired, $ctx);
        if ($server !== null) {
            if ($this->destination($server, $desired['destination'] ?? null) === null) {
                $errors[] = new ConfigError("{$base}.destination", $server->destinations()->count() > 1
                    ? 'This server has more than one destination. Add the network name of one.'
                    : 'No destination with this network is on the server.');
            }
        }

        $errors = [...$errors, ...$this->validateSource($base, $desired['source'] ?? [], $ctx), ...$this->validateChildren($base, $desired, $ctx)];

        $columns = $this->columnsFromFile($desired);
        $values = [...$columns['applications'], ...$columns[self::SETTINGS]];
        $values['docker_compose_domains'] = data_get($desired, 'compose.domains');
        $domains = array_values((array) ($desired['domains'] ?? []));
        if ($domains !== []) {
            $values['domains'] = implode(',', $domains);
        }
        $rules = array_intersect_key(sharedDataApplications(), array_filter($values, fn ($value) => $value !== null));
        unset($rules['manual_webhook_secret_github'], $rules['manual_webhook_secret_gitlab'], $rules['manual_webhook_secret_bitbucket'], $rules['manual_webhook_secret_gitea']);
        $validator = Validator::make($values, $rules);
        foreach ($validator->errors()->messages() as $column => $messages) {
            $path = $column === 'domains' ? 'domains' : ($this->pathForColumn($column) ?? $column);
            $errors[] = new ConfigError("{$base}.{$path}", $messages[0]);
        }

        if ($domains !== []) {
            $existing = $current ?? $this->find($ctx->environment, $key);
            $conflicts = checkIfDomainIsAlreadyUsedViaAPI(array_map(FieldValues::normalizeDomain(...), $domains), (string) $ctx->teamId, $existing?->uuid);
            foreach ($conflicts['conflicts'] ?? [] as $conflict) {
                $errors[] = new ConfigError("{$base}.domains", $conflict['message']);
            }
        }

        return $errors;
    }

    /**
     * @return list<ConfigError>
     */
    private function validateChildren(string $base, array $desired, ApplyContext $ctx): array
    {
        $errors = [];
        $token = $desired['secret_manager']['token'] ?? null;
        if ($token !== null && ! IntegrationToken::query()->where('team_id', $ctx->teamId)->where('name', $token)->exists()) {
            $errors[] = new ConfigError("{$base}.secret_manager.token", "No integration token named \"{$token}\" is in this team.");
        }
        foreach (array_values((array) ($desired['additional_servers'] ?? [])) as $index => $entry) {
            $name = is_array($entry) ? ($entry['server'] ?? null) : $entry;
            $matches = Server::query()->where('team_id', $ctx->teamId)->get()->filter(fn (Server $server) => $server->name === $name);
            if ($matches->count() !== 1) {
                $errors[] = new ConfigError("{$base}.additional_servers.{$index}.server", 'Use the name of exactly one server of this team.');
            }
        }
        foreach (array_keys((array) ($desired['files'] ?? [])) as $mount) {
            try {
                validateFileMountPath((string) $mount, 'file mount path');
            } catch (\Throwable $e) {
                $errors[] = new ConfigError("{$base}.files.{$mount}", $e->getMessage());
            }
        }

        return $errors;
    }

    /**
     * @return list<ConfigError>
     */
    private function validateSource(string $base, array $source, ApplyContext $ctx): array
    {
        $kinds = array_values(array_intersect(['github_app', 'gitlab_app', 'gitea_app', 'deploy_key'], array_keys($source)));
        if (count($kinds) > 1) {
            return [new ConfigError("{$base}.source", 'Use one of github_app, gitea_app, or deploy_key.')];
        }
        if ($kinds === []) {
            return [];
        }
        $kind = $kinds[0];
        $path = "{$base}.source.{$kind}";
        if ($kind === 'gitlab_app') {
            return [new ConfigError($path, 'GitLab app sources are not supported in coolify.json yet. Use a deploy key.')];
        }
        if ($this->sourceModel($kind, (string) $source[$kind], $ctx) === null) {
            return [new ConfigError($path, "No {$kind} named \"{$source[$kind]}\" is available to this team.")];
        }

        return [];
    }

    private function sourceModel(string $kind, string $name, ApplyContext $ctx): ?Model
    {
        return match ($kind) {
            'deploy_key' => PrivateKey::query()->where('team_id', $ctx->teamId)->where('name', $name)->first(),
            'github_app', 'gitea_app' => self::SOURCE_KINDS[$kind]::query()
                ->where('name', $name)
                ->where(fn ($query) => $query->where('team_id', $ctx->teamId)->orWhere('is_system_wide', true))
                ->first(),
            default => null,
        };
    }

    public function create(string $key, array $desired, ApplyContext $ctx): Model
    {
        $application = new Application;
        $application->environment_id = $ctx->environment->id;
        $application->config_key = $key;
        $this->fill($application, $key, $desired, $ctx);
        $application->withoutAuditLogging(fn () => $application->save());

        $application->load('settings');
        $this->fillSettings($application, $desired);
        $this->writeChildren($application, $desired, $ctx);
        $this->refreshLabels($application, $desired);

        return $application;
    }

    public function update(Model $resource, array $desired, ApplyContext $ctx): void
    {
        /** @var Application $resource */
        $key = (string) $resource->config_key;
        $this->fill($resource, $key, $desired, $ctx);
        $resource->withoutAuditLogging(fn () => $resource->save());
        $this->fillSettings($resource, $desired);
        $this->writeChildren($resource, $desired, $ctx);
        $this->refreshLabels($resource, $desired);
    }

    private function writeChildren(Application $application, array $desired, ApplyContext $ctx): void
    {
        foreach ($this->children() as $child) {
            $child->write($application, $desired[$child->key()] ?? null, $ctx);
        }
    }

    public function delete(Model $resource, bool $deleteData): void
    {
        DeleteResourceJob::dispatch($resource, deleteVolumes: $deleteData, deleteConnectedNetworks: true, deleteConfigurations: true, dockerCleanup: true)->afterCommit();
    }

    public function referenceValue(Model $resource, string $property): ?string
    {
        /** @var Application $resource */
        $port = explode(',', (string) $resource->ports_exposes)[0] ?: null;

        return match ($property) {
            'host' => $resource->uuid,
            'port' => $port,
            'internal_url' => $port === null ? "http://{$resource->uuid}" : "http://{$resource->uuid}:{$port}",
            default => null,
        };
    }

    public function destructiveFieldChange(string $path, mixed $from, mixed $to): bool
    {
        return false;
    }

    private function fill(Application $application, string $key, array $desired, ApplyContext $ctx): void
    {
        $application->forceFill($this->columnsFromFile($desired)['applications']);
        $application->name = $desired['name'] ?? $key;

        $server = $this->server($desired, $ctx) ?? throw new LogicException("Server \"{$desired['server']}\" not found.");
        $destination = $this->destination($server, $desired['destination'] ?? null) ?? throw new LogicException('Destination not found.');
        $application->destination_id = $destination->id;
        $application->destination_type = $destination->getMorphClass();

        $domains = array_values(array_map(FieldValues::normalizeDomain(...), (array) ($desired['domains'] ?? [])));
        $application->fqdn = $domains === [] ? null : implode(',', $domains);

        $this->fillSource($application, $desired['source'] ?? [], $ctx);
    }

    private function fillSource(Application $application, array $source, ApplyContext $ctx): void
    {
        $application->private_key_id = null;
        $kind = collect(['github_app', 'gitea_app', 'deploy_key'])->first(fn ($candidate) => isset($source[$candidate]));

        if ($kind === 'deploy_key') {
            $application->source_type = null;
            $application->source_id = null;
            $application->repository_project_id = null;
            $application->private_key_id = $this->sourceModel('deploy_key', $source['deploy_key'], $ctx)?->id;

            return;
        }
        if ($kind === null) {
            $application->source_type = GithubApp::class;
            $application->source_id = 0;
            $application->repository_project_id = null;

            return;
        }

        $model = $this->sourceModel($kind, (string) $source[$kind], $ctx) ?? throw new LogicException("Git source \"{$source[$kind]}\" not found.");
        $changed = $application->source_type !== $model::class
            || (int) $application->source_id !== (int) $model->id
            || (string) $application->getOriginal('git_repository') !== (string) $application->git_repository
            || $application->repository_project_id === null;
        $application->source_type = $model::class;
        $application->source_id = $model->id;
        if ($changed) {
            $lookup = app(LookupGitAppRepository::class);
            $result = $kind === 'github_app'
                ? $lookup->github($model, (string) $application->git_repository)
                : $lookup->gitea($model, (string) $application->git_repository);
            $application->git_repository = $result['repository'];
            $application->repository_project_id = $result['repository_project_id'];
        }
    }

    private function fillSettings(Application $application, array $desired): void
    {
        $settings = $application->settings()->firstOrCreate([]);
        $settings->forceFill($this->columnsFromFile($desired)[self::SETTINGS]);
        $settings->save();
        $application->setRelation('settings', $settings);
    }

    private function refreshLabels(Application $application, array $desired): void
    {
        $labels = $application->settings->is_container_label_readonly_enabled
            ? implode("\n", generateLabelsApplication($application))
            : (string) ($desired['labels'] ?? '');
        $encoded = $labels === '' ? null : base64_encode($labels);
        if ($application->custom_labels !== $encoded) {
            $application->custom_labels = $encoded;
            $application->withoutAuditLogging(fn () => $application->save());
        }
    }

    /**
     * Stored column values for every plain managed field: the file value, or the default.
     *
     * @return array{applications: array<string, mixed>, application_settings: array<string, mixed>}
     */
    private function columnsFromFile(array $desired): array
    {
        $columns = ['applications' => [], self::SETTINGS => []];
        foreach ($this->fieldMap()->managed() as $field) {
            if ($field->path === null || in_array($field->path, $this->customPaths(), true)) {
                continue;
            }
            $value = data_get($desired, $field->path, $field->default);
            $columns[$field->table ?? 'applications'][$field->column] = FieldValues::toStored($field, FieldValues::cast($field->jsonType, $value));
        }

        return $columns;
    }

    private function pathForColumn(string $column): ?string
    {
        foreach ($this->fieldMap()->managed() as $field) {
            if ($field->column === $column) {
                return $field->path;
            }
        }

        return null;
    }
}
