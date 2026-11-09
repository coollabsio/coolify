<?php

namespace App\Services\ProjectConfig\Adapters;

use App\Actions\Service\CreateServiceFromCompose;
use App\Jobs\DeleteResourceJob;
use App\Models\Environment;
use App\Models\Service;
use App\Services\ProjectConfig\Adapters\Concerns\MapsFields;
use App\Services\ProjectConfig\Adapters\Concerns\ResolvesDestination;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Children\ChildRecords;
use App\Services\ProjectConfig\Children\EnvironmentVariables;
use App\Services\ProjectConfig\Children\ScheduledTasks;
use App\Services\ProjectConfig\Children\SecretManager;
use App\Services\ProjectConfig\Children\Tags;
use App\Services\ProjectConfig\ConfigError;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use App\Services\ProjectConfig\FieldValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * A service stack from a one-click template or a raw Compose file.
 *
 * A template entry is resolved to its Compose file once, when the service is created. Later template
 * updates in Coolify do not change the service, so the same file keeps giving the same result.
 */
class ServiceAdapter implements ResourceAdapter
{
    use MapsFields;
    use ResolvesDestination;

    /** Settings of a Compose service that runs an application: file key => [column, default, JSON type]. */
    private const APPLICATION_FIELDS = [
        'description' => ['description', null, 'string'],
        'human_name' => ['human_name', null, 'string'],
        'exclude_from_status' => ['exclude_from_status', false, 'boolean'],
        'is_log_drain_enabled' => ['is_log_drain_enabled', false, 'boolean'],
        'is_include_timestamps' => ['is_include_timestamps', false, 'boolean'],
        'is_gzip_enabled' => ['is_gzip_enabled', true, 'boolean'],
        'is_stripprefix_enabled' => ['is_stripprefix_enabled', true, 'boolean'],
        'redirect' => ['redirect', 'both', 'string'],
        'is_force_https_enabled' => ['is_force_https_enabled', true, 'boolean'],
        'max_restart_count' => ['max_restart_count', 0, 'integer'],
        'traefik_certificate_resolver' => ['traefik_certificate_resolver', null, 'string'],
        'is_permanent_redirect_enabled' => ['is_permanent_redirect_enabled', false, 'boolean'],
        'domain_port_overrides' => ['domain_port_overrides', null, 'object'],
        'noindex_domains' => ['noindex_domains', null, 'array'],
    ];

    /** Settings of a Compose service that runs a database. */
    private const DATABASE_FIELDS = [
        'description' => ['description', null, 'string'],
        'human_name' => ['human_name', null, 'string'],
        'exclude_from_status' => ['exclude_from_status', false, 'boolean'],
        'is_public' => ['is_public', false, 'boolean'],
        'public_port' => ['public_port', null, 'integer'],
        'public_port_timeout' => ['public_port_timeout', 3600, 'integer'],
        'is_log_drain_enabled' => ['is_log_drain_enabled', false, 'boolean'],
        'is_include_timestamps' => ['is_include_timestamps', false, 'boolean'],
        'is_gzip_enabled' => ['is_gzip_enabled', true, 'boolean'],
        'is_stripprefix_enabled' => ['is_stripprefix_enabled', true, 'boolean'],
        'custom_type' => ['custom_type', null, 'string'],
    ];

    private ?FieldMap $map = null;

    /** @var list<ChildRecords>|null */
    private ?array $children = null;

    public function type(): string
    {
        return 'service';
    }

    public function modelClass(): string
    {
        return Service::class;
    }

    protected function customPaths(): array
    {
        return ['name', 'server', 'destination', 'template', 'compose'];
    }

    public function fieldMap(): FieldMap
    {
        return $this->map ??= new FieldMap('services', [
            Field::managed('name', 'name'),
            Field::managed('description', 'description'),
            Field::managed('server_id', 'server'),
            Field::managed('destination_type', 'destination'),
            Field::managed('destination_id', 'destination'),
            Field::managed('service_type', 'template'),
            Field::managed('docker_compose_raw', 'compose'),
            Field::managed('connect_to_docker_network', 'connect_to_docker_network', false, 'boolean'),
            Field::managed('is_container_label_escape_enabled', 'settings.is_container_label_escape_enabled', true, 'boolean'),
            Field::managed('redact_all_env_values_in_logs', 'settings.redact_all_env_values_in_logs', true, 'boolean'),
            Field::managed('include_coolify_default_variables', 'settings.include_coolify_default_variables', true, 'boolean'),
            Field::managed('is_maintenance_enabled', 'maintenance.enabled', false, 'boolean'),
            Field::managed('maintenance_page', 'maintenance.page'),
            Field::derived('docker_compose', 'Coolify generates it from the Compose file.'),
            Field::derived('compose_parsing_version', 'Coolify sets the parser version when it creates the service.'),
            Field::state('config_hash', 'Coolify stores the hash of the last started configuration.'),
            Field::derived('config_meta', 'Coolify stores which Compose services get their domains from coolify.json.'),
        ], ['services' => ['environment_id']]);
    }

    /**
     * @return list<ChildRecords>
     */
    public function children(): array
    {
        return $this->children ??= [new EnvironmentVariables, new ScheduledTasks, new Tags, new SecretManager];
    }

    public function childTables(): array
    {
        $maps = ['service_applications' => $this->subMap('service_applications', self::APPLICATION_FIELDS, [
            Field::managed('fqdn', 'services.*.domains'),
            Field::derived('name', 'The name of the service in the Compose file.'),
            Field::derived('image', 'Coolify reads it from the Compose file.'),
            Field::derived('ports', 'Coolify reads it from the Compose file.'),
            Field::derived('exposes', 'Coolify reads it from the Compose file.'),
            Field::derived('required_fqdn', 'Coolify reads it from the Compose file.'),
            Field::derived('is_migrated', 'Coolify sets it when it migrates old services.'),
            Field::state('is_removed_from_compose', 'Coolify sets it when the Compose file no longer has the service.'),
            Field::state('status', 'Runtime status of the container.'),
            Field::state('last_online_at', 'Runtime status of the container.'),
            Field::state('restart_count', 'Runtime restart counter.'),
            Field::state('restart_limit_reached', 'Runtime restart counter.'),
            Field::state('last_restart_at', 'Runtime restart counter.'),
            Field::state('last_restart_type', 'Runtime restart counter.'),
            Field::state('domain_dns_statuses', 'Result of the last DNS check.'),
        ]), 'service_databases' => $this->subMap('service_databases', self::DATABASE_FIELDS, [
            Field::derived('name', 'The name of the service in the Compose file.'),
            Field::derived('image', 'Coolify reads it from the Compose file.'),
            Field::derived('ports', 'Coolify reads it from the Compose file.'),
            Field::derived('exposes', 'Coolify reads it from the Compose file.'),
            Field::derived('is_migrated', 'Coolify sets it when it migrates old services.'),
            Field::state('is_removed_from_compose', 'Coolify sets it when the Compose file no longer has the service.'),
            Field::state('status', 'Runtime status of the container.'),
            Field::state('last_online_at', 'Runtime status of the container.'),
        ])];
        foreach ($this->children() as $child) {
            if ($child->map() !== null) {
                $maps[$child->key()] = $child->map();
            }
        }

        return $maps;
    }

    private function subMap(string $table, array $definitions, array $extra): FieldMap
    {
        $fields = $extra;
        foreach ($definitions as $path => [$column, $default, $jsonType]) {
            $fields[] = Field::managed($column, "services.*.{$path}", $default, $jsonType);
        }

        return new FieldMap($table, $fields, [$table => ['service_id']]);
    }

    public function schema(): array
    {
        $properties = $this->mappedFieldsSchema($this->fieldMap());
        $sub = ['domains' => ['type' => 'array', 'items' => ['type' => 'string']]];
        foreach ([...self::APPLICATION_FIELDS, ...self::DATABASE_FIELDS] as $path => [, , $jsonType]) {
            $sub[$path] = match ($jsonType) {
                'array' => ['type' => 'array', 'items' => ['type' => 'string']],
                'object' => ['type' => 'object'],
                default => ['type' => [$jsonType, 'null']],
            };
        }
        ksort($sub);
        $properties += [
            'type' => ['const' => 'service'],
            'name' => ['type' => 'string'],
            'server' => ['type' => 'string'],
            'destination' => ['type' => 'string'],
            'template' => ['type' => 'string'],
            'compose' => ['type' => 'string'],
            'services' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $sub]],
        ];
        foreach ($this->children() as $child) {
            $properties[$child->key()] = $child->schema();
        }
        ksort($properties);

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
    }

    public function find(Environment $environment, string $key): ?Model
    {
        return Service::query()->where('environment_id', $environment->id)->where('config_key', $key)->first();
    }

    public function findByUuid(Environment $environment, string $uuid): ?Model
    {
        return Service::query()->where('environment_id', $environment->id)->where('uuid', $uuid)->where('id', '!=', 0)->first();
    }

    public function unkeyed(Environment $environment): Collection
    {
        return Service::query()->where('environment_id', $environment->id)->whereNull('config_key')->where('id', '!=', 0)->orderBy('id')->get();
    }

    public function canonical(string $key, array $desired): array
    {
        $desired['name'] ??= $key;
        if (isset($desired['template']) && in_array($desired['template'], NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK) && ($desired['connect_to_docker_network'] ?? true) === true) {
            unset($desired['connect_to_docker_network']);
        }
        $desired = $this->dropMappedDefaults($this->fieldMap(), $desired);
        $services = [];
        foreach ((array) ($desired['services'] ?? []) as $name => $entry) {
            $entry = $this->canonicalSub((array) $entry, [...self::APPLICATION_FIELDS, ...self::DATABASE_FIELDS]);
            if ($entry !== []) {
                $services[$name] = $entry;
            }
        }
        unset($desired['services']);
        if ($services !== []) {
            ksort($services);
            $desired['services'] = $services;
        }
        foreach ($this->children() as $child) {
            $value = $child->canonical($desired[$child->key()] ?? null);
            unset($desired[$child->key()]);
            if ($value !== null) {
                $desired[$child->key()] = $value;
            }
        }
        ksort($desired);

        return $desired;
    }

    private function canonicalSub(array $entry, array $definitions): array
    {
        if (isset($entry['domains'])) {
            $entry['domains'] = array_values(array_map(FieldValues::normalizeDomain(...), (array) $entry['domains']));
            if ($entry['domains'] === []) {
                unset($entry['domains']);
            }
        }
        foreach ($definitions as $path => [, $default, $jsonType]) {
            if (! array_key_exists($path, $entry)) {
                continue;
            }
            $value = FieldValues::cast($jsonType, $entry[$path]);
            if ($value === null || $value === [] || $value === FieldValues::cast($jsonType, $default)) {
                unset($entry[$path]);
            } else {
                $entry[$path] = $value;
            }
        }
        ksort($entry);

        return $entry;
    }

    public function read(Model $resource, ApplyContext $ctx): array
    {
        /** @var Service $resource */
        $result = ['type' => 'service', 'name' => $resource->name];
        $result += $this->readMappedFields($this->fieldMap(), ['services' => $resource]);
        $result += $this->readDestination($resource->destination);
        if (filled($resource->service_type)) {
            $result['template'] = $resource->service_type;
            if (in_array($resource->service_type, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK) && $resource->connect_to_docker_network) {
                unset($result['connect_to_docker_network']);
            }
        } else {
            $result['compose'] = (string) $resource->docker_compose_raw;
        }

        $services = [];
        $managedDomains = (array) ($resource->config_meta['managed_domains'] ?? []);
        foreach ($resource->applications()->get() as $application) {
            $entry = $this->readSub($application, self::APPLICATION_FIELDS);
            $domains = FieldValues::domainList($application->fqdn);
            if ($domains !== [] && in_array($application->name, $managedDomains, true)) {
                $entry['domains'] = $domains;
            }
            $entry = $this->canonicalSub($entry, self::APPLICATION_FIELDS);
            if ($entry !== []) {
                $services[$application->name] = $entry;
            }
        }
        foreach ($resource->databases()->get() as $database) {
            $entry = $this->canonicalSub($this->readSub($database, self::DATABASE_FIELDS), self::DATABASE_FIELDS);
            if ($entry !== []) {
                $services[$database->name] = $entry;
            }
        }
        if ($services !== []) {
            ksort($services);
            $result['services'] = $services;
        }

        foreach ($this->children() as $child) {
            $value = $child->read($resource);
            if ($value !== null) {
                $result[$child->key()] = $value;
            }
        }
        ksort($result);

        return $result;
    }

    private function readSub(Model $model, array $definitions): array
    {
        $entry = [];
        foreach ($definitions as $path => [$column]) {
            $value = $model->getAttribute($column);
            $entry[$path] = $value instanceof \Stringable ? (string) $value : $value;
        }

        return $entry;
    }

    public function validate(string $key, array $desired, ApplyContext $ctx, ?Model $current = null): array
    {
        $base = "resources.{$key}";
        $errors = [];

        $hasTemplate = isset($desired['template']);
        $hasCompose = isset($desired['compose']);
        if ($hasTemplate === $hasCompose) {
            return [new ConfigError("{$base}.template", 'Use exactly one of "template" and "compose".')];
        }

        $compose = $hasTemplate ? CreateServiceFromCompose::templateCompose((string) $desired['template']) : (string) $desired['compose'];
        if ($compose === null) {
            return [new ConfigError("{$base}.template", "No one-click template is named \"{$desired['template']}\".")];
        }
        try {
            validateDockerComposeForInjection($compose);
        } catch (Throwable $e) {
            return [new ConfigError("{$base}.compose", $e->getMessage())];
        }

        $names = $this->composeServiceNames($compose);
        foreach (array_keys((array) ($desired['services'] ?? [])) as $name) {
            if (! in_array($name, $names, true)) {
                $errors[] = new ConfigError("{$base}.services.{$name}", 'The Compose file has no service with this name.');
            }
        }

        $server = $this->server($desired, $ctx);
        if ($server !== null && $this->destination($server, $desired['destination'] ?? null) === null) {
            $errors[] = new ConfigError("{$base}.destination", 'This server has more than one destination. Add the network name of one.');
        }

        foreach (($desired['env'] ?? []) as $name => $entry) {
            if (str_starts_with((string) ($entry['ref'] ?? ''), 'vault.')) {
                $errors[] = new ConfigError("{$base}.env.{$name}", 'vault references work on applications only.');
            }
        }

        foreach ((array) ($desired['services'] ?? []) as $name => $entry) {
            $domains = array_map(FieldValues::normalizeDomain(...), (array) ($entry['domains'] ?? []));
            if ($domains === []) {
                continue;
            }
            $existing = $current instanceof Service ? $current->applications()->where('name', $name)->first() : null;
            $conflicts = checkIfDomainIsAlreadyUsedViaAPI($domains, (string) $ctx->teamId, $existing?->uuid);
            foreach ($conflicts['conflicts'] ?? [] as $conflict) {
                $errors[] = new ConfigError("{$base}.services.{$name}.domains", $conflict['message']);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function composeServiceNames(?string $compose): array
    {
        try {
            $parsed = Yaml::parse((string) $compose);
        } catch (Throwable) {
            return [];
        }

        return array_map('strval', array_keys((array) ($parsed['services'] ?? [])));
    }

    public function create(string $key, array $desired, ApplyContext $ctx): Model
    {
        $server = $this->server($desired, $ctx) ?? throw new LogicException("Server \"{$desired['server']}\" not found.");
        $destination = $this->destination($server, $desired['destination'] ?? null) ?? throw new LogicException('Destination not found.');
        $template = $desired['template'] ?? null;
        $compose = $template !== null ? CreateServiceFromCompose::templateCompose($template) : $desired['compose'];

        $service = app(CreateServiceFromCompose::class)->handle(
            environment: $ctx->environment,
            server: $server,
            destination: $destination,
            dockerComposeRaw: (string) $compose,
            templateKey: $template,
            attributes: $this->columnsFromFile($desired) + ['config_key' => $key],
            connectToDockerNetwork: (bool) ($desired['connect_to_docker_network'] ?? false),
            name: $desired['name'] ?? $key,
        );
        $this->writeServices($service, $desired);
        $this->writeChildren($service, $desired, $ctx);
        $service->parse();

        return $service;
    }

    public function update(Model $resource, array $desired, ApplyContext $ctx): void
    {
        /** @var Service $resource */
        $server = $this->server($desired, $ctx) ?? throw new LogicException("Server \"{$desired['server']}\" not found.");
        $destination = $this->destination($server, $desired['destination'] ?? null) ?? throw new LogicException('Destination not found.');
        $values = $this->columnsFromFile($desired) + [
            'name' => $desired['name'] ?? $resource->config_key,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ];
        if (isset($desired['compose'])) {
            $values['service_type'] = null;
            $values['docker_compose_raw'] = $desired['compose'];
        } elseif (($desired['template'] ?? null) !== $resource->service_type) {
            $values['service_type'] = $desired['template'];
            $values['docker_compose_raw'] = CreateServiceFromCompose::templateCompose($desired['template']);
        }
        if (isset($desired['template']) && in_array($desired['template'], NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK) && ! isset($desired['connect_to_docker_network'])) {
            $values['connect_to_docker_network'] = true;
        }
        $resource->forceFill($values)->save();
        $resource->parse();
        $this->writeServices($resource->fresh(), $desired);
        $this->writeChildren($resource, $desired, $ctx);
        $resource->parse();
    }

    /**
     * @return array<string, mixed>
     */
    private function columnsFromFile(array $desired): array
    {
        $columns = [];
        foreach ($this->fieldMap()->managed() as $field) {
            if ($field->path === null || in_array($field->path, $this->customPaths(), true)) {
                continue;
            }
            $columns[$field->column] = FieldValues::cast($field->jsonType, data_get($desired, $field->path, $field->default));
        }

        return $columns;
    }

    private function writeServices(Service $service, array $desired): void
    {
        $entries = (array) ($desired['services'] ?? []);
        $managedDomains = [];
        foreach ($service->applications()->get() as $application) {
            $entry = (array) ($entries[$application->name] ?? []);
            $values = $this->subValues($entry, self::APPLICATION_FIELDS);
            $domains = array_map(FieldValues::normalizeDomain(...), (array) ($entry['domains'] ?? []));
            if ($domains !== []) {
                $values['fqdn'] = implode(',', $domains);
                $managedDomains[] = $application->name;
            }
            $application->forceFill($values)->save();
        }
        $service->forceFill(['config_meta' => $managedDomains === [] ? null : ['managed_domains' => $managedDomains]])->saveQuietly();
        foreach ($service->databases()->get() as $database) {
            $database->forceFill($this->subValues((array) ($entries[$database->name] ?? []), self::DATABASE_FIELDS))->save();
        }
    }

    private function subValues(array $entry, array $definitions): array
    {
        $values = [];
        foreach ($definitions as $path => [$column, $default, $jsonType]) {
            $values[$column] = FieldValues::cast($jsonType, $entry[$path] ?? $default);
        }

        return $values;
    }

    private function writeChildren(Service $service, array $desired, ApplyContext $ctx): void
    {
        foreach ($this->children() as $child) {
            $child->write($service, $desired[$child->key()] ?? null, $ctx);
        }
    }

    public function delete(Model $resource, bool $deleteData): void
    {
        DeleteResourceJob::dispatch($resource, deleteVolumes: $deleteData, deleteConnectedNetworks: true, deleteConfigurations: true, dockerCleanup: true)->afterCommit();
    }

    public function referenceValue(Model $resource, string $property): ?string
    {
        return null;
    }

    public function destructiveFieldChange(string $path, mixed $from, mixed $to): bool
    {
        if (str_starts_with($path, 'services.') && $to === null) {
            return true;
        }
        if ($path === 'compose' || $path === 'template') {
            $before = $path === 'template' ? CreateServiceFromCompose::templateCompose((string) $from) : (string) $from;
            $after = $path === 'template' ? CreateServiceFromCompose::templateCompose((string) $to) : (string) $to;

            return array_diff($this->composeServiceNames($before), $this->composeServiceNames($after)) !== [];
        }

        return false;
    }
}
