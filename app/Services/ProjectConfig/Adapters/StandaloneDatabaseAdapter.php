<?php

namespace App\Services\ProjectConfig\Adapters;

use App\Jobs\DeleteResourceJob;
use App\Models\Environment;
use App\Models\S3Storage;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Rules\DockerImageFormat;
use App\Services\ProjectConfig\Adapters\Concerns\MapsFields;
use App\Services\ProjectConfig\Adapters\Concerns\ResolvesDestination;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Children\ChildRecords;
use App\Services\ProjectConfig\Children\EnvironmentVariables;
use App\Services\ProjectConfig\Children\FileStorages;
use App\Services\ProjectConfig\Children\PersistentStorages;
use App\Services\ProjectConfig\Children\ScheduledBackups;
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

/**
 * One standalone database type. The type list below holds what differs between engines.
 */
class StandaloneDatabaseAdapter implements ResourceAdapter
{
    use MapsFields;
    use ResolvesDestination;

    /**
     * model, table, create helper, internal port, default image, default volume keys, Coolify-made variables,
     * secret columns, and engine fields as [column, file path, default, JSON type].
     */
    public const TYPES = [
        'postgresql' => [
            'model' => StandalonePostgresql::class, 'table' => 'standalone_postgresqls', 'create' => 'create_standalone_postgresql',
            'port' => 5432, 'image' => 'postgres:18-alpine', 'volumes' => ['postgres-data'], 'variables' => [],
            'secrets' => ['postgres_password'],
            'fields' => [
                ['postgres_user', 'credentials.user', 'postgres', 'string'],
                ['postgres_db', 'credentials.database', 'postgres', 'string'],
                ['postgres_initdb_args', 'config.initdb_args', null, 'string'],
                ['postgres_host_auth_method', 'config.host_auth_method', null, 'string'],
                ['postgres_conf', 'config.file', null, 'string'],
                ['init_scripts', 'init_scripts', null, 'array'],
                ['enable_ssl', 'ssl.enabled', false, 'boolean'],
                ['ssl_mode', 'ssl.mode', 'require', 'string'],
            ],
        ],
        'mysql' => [
            'model' => StandaloneMysql::class, 'table' => 'standalone_mysqls', 'create' => 'create_standalone_mysql',
            'port' => 3306, 'image' => 'mysql:9.7', 'volumes' => ['mysql-data'], 'variables' => [],
            'secrets' => ['mysql_root_password', 'mysql_password'],
            'fields' => [
                ['mysql_user', 'credentials.user', 'mysql', 'string'],
                ['mysql_database', 'credentials.database', 'default', 'string'],
                ['mysql_conf', 'config.file', null, 'string'],
                ['enable_ssl', 'ssl.enabled', false, 'boolean'],
                ['ssl_mode', 'ssl.mode', 'REQUIRED', 'string'],
            ],
        ],
        'mariadb' => [
            'model' => StandaloneMariadb::class, 'table' => 'standalone_mariadbs', 'create' => 'create_standalone_mariadb',
            'port' => 3306, 'image' => 'mariadb:12.3', 'volumes' => ['mariadb-data'], 'variables' => [],
            'secrets' => ['mariadb_root_password', 'mariadb_password'],
            'fields' => [
                ['mariadb_user', 'credentials.user', 'mariadb', 'string'],
                ['mariadb_database', 'credentials.database', 'default', 'string'],
                ['mariadb_conf', 'config.file', null, 'string'],
                ['enable_ssl', 'ssl.enabled', false, 'boolean'],
            ],
        ],
        'mongodb' => [
            'model' => StandaloneMongodb::class, 'table' => 'standalone_mongodbs', 'create' => 'create_standalone_mongodb',
            'port' => 27017, 'image' => 'mongo:9', 'volumes' => ['mongodb-configdb', 'mongodb-db'], 'variables' => [],
            'secrets' => ['mongo_initdb_root_password'],
            'fields' => [
                ['mongo_initdb_root_username', 'credentials.user', 'root', 'string'],
                ['mongo_initdb_database', 'credentials.database', 'default', 'string'],
                ['mongo_conf', 'config.file', null, 'string'],
                ['enable_ssl', 'ssl.enabled', false, 'boolean'],
                ['ssl_mode', 'ssl.mode', 'require', 'string'],
            ],
        ],
        'redis' => [
            'model' => StandaloneRedis::class, 'table' => 'standalone_redis', 'create' => 'create_standalone_redis',
            'port' => 6379, 'image' => 'valkey/valkey:9-alpine', 'volumes' => ['redis-data'], 'variables' => ['REDIS_PASSWORD', 'REDIS_USERNAME'],
            'secrets' => [],
            'fields' => [
                ['redis_conf', 'config.file', null, 'string'],
                ['enable_ssl', 'ssl.enabled', false, 'boolean'],
            ],
        ],
        'keydb' => [
            'model' => StandaloneKeydb::class, 'table' => 'standalone_keydbs', 'create' => 'create_standalone_keydb',
            'port' => 6379, 'image' => 'eqalpha/keydb:latest', 'volumes' => ['keydb-data'], 'variables' => [],
            'secrets' => ['keydb_password'],
            'fields' => [
                ['keydb_conf', 'config.file', null, 'string'],
                ['enable_ssl', 'ssl.enabled', false, 'boolean'],
            ],
        ],
        'dragonfly' => [
            'model' => StandaloneDragonfly::class, 'table' => 'standalone_dragonflies', 'create' => 'create_standalone_dragonfly',
            'port' => 6379, 'image' => 'docker.dragonflydb.io/dragonflydb/dragonfly', 'volumes' => ['dragonfly-data'], 'variables' => [],
            'secrets' => ['dragonfly_password'],
            'fields' => [
                ['enable_ssl', 'ssl.enabled', false, 'boolean'],
            ],
        ],
        'clickhouse' => [
            'model' => StandaloneClickhouse::class, 'table' => 'standalone_clickhouses', 'create' => 'create_standalone_clickhouse',
            'port' => 8123, 'image' => 'clickhouse/clickhouse-server:26.8', 'volumes' => ['clickhouse-data'], 'variables' => [],
            'secrets' => ['clickhouse_admin_password'],
            'fields' => [
                ['clickhouse_admin_user', 'credentials.user', 'default', 'string'],
                ['clickhouse_db', 'credentials.database', 'default', 'string'],
            ],
        ],
        'sqlite' => [
            'model' => StandaloneSqlite::class, 'table' => 'standalone_sqlites', 'create' => 'create_standalone_sqlite',
            'port' => null, 'image' => 'peakimages/sqlite:3.54.1-v0.2.0', 'volumes' => ['sqlite-data'], 'variables' => [],
            'secrets' => [],
            'fields' => [
                ['sqlite_databases', 'config.databases', 'database.sqlite', 'string'],
            ],
        ],
    ];

    /** Common columns that a table does not have. */
    private const WITHOUT = [
        'sqlite' => ['public_port', 'ports_mappings', 'public_port_timeout'],
        'mariadb' => ['is_include_timestamps'],
    ];

    private ?FieldMap $map = null;

    /** @var list<ChildRecords>|null */
    private ?array $children = null;

    public function __construct(private readonly string $type) {}

    private function config(string $key): mixed
    {
        return self::TYPES[$this->type][$key];
    }

    public function type(): string
    {
        return $this->type;
    }

    public function modelClass(): string
    {
        return $this->config('model');
    }

    protected function customPaths(): array
    {
        return ['name', 'server', 'destination'];
    }

    public function fieldMap(): FieldMap
    {
        if ($this->map !== null) {
            return $this->map;
        }
        $without = self::WITHOUT[$this->type] ?? [];
        $common = [
            ['description', 'description', null, 'string'],
            ['image', 'image', $this->config('image'), 'string'],
            ['is_public', 'public.enabled', false, 'boolean'],
            ['public_port', 'public.port', null, 'integer'],
            ['public_port_timeout', 'public.port_timeout', 3600, 'integer'],
            ['ports_mappings', 'ports.mappings', null, 'string'],
            ['limits_memory', 'limits.memory', '0', 'string'],
            ['limits_memory_swap', 'limits.memory_swap', '0', 'string'],
            ['limits_memory_swappiness', 'limits.memory_swappiness', 60, 'integer'],
            ['limits_memory_reservation', 'limits.memory_reservation', '0', 'string'],
            ['limits_cpus', 'limits.cpus', '0', 'string'],
            ['limits_cpuset', 'limits.cpuset', null, 'string'],
            ['limits_cpu_shares', 'limits.cpu_shares', 1024, 'integer'],
            ['health_check_enabled', 'health_check.enabled', true, 'boolean'],
            ['health_check_interval', 'health_check.interval', 15, 'integer'],
            ['health_check_timeout', 'health_check.timeout', 5, 'integer'],
            ['health_check_retries', 'health_check.retries', 5, 'integer'],
            ['health_check_start_period', 'health_check.start_period', 5, 'integer'],
            ['custom_docker_run_options', 'docker_run_options', null, 'string'],
            ['redact_all_env_values_in_logs', 'settings.redact_all_env_values_in_logs', true, 'boolean'],
            ['include_coolify_default_variables', 'settings.include_coolify_default_variables', true, 'boolean'],
            ['is_log_drain_enabled', 'settings.is_log_drain_enabled', false, 'boolean'],
            ['is_include_timestamps', 'settings.is_include_timestamps', false, 'boolean'],
        ];

        $fields = [
            Field::managed('name', 'name'),
            Field::managed('destination_type', 'destination'),
            Field::managed('destination_id', 'destination'),
        ];
        foreach ([...$common, ...$this->config('fields')] as [$column, $path, $default, $jsonType]) {
            if (! in_array($column, $without, true)) {
                $fields[] = Field::managed($column, $path, $default, $jsonType);
            }
        }
        foreach ($this->config('secrets') as $column) {
            $fields[] = Field::secret($column, 'A database password is a credential. Coolify generates it.');
        }
        foreach (['status', 'started_at', 'config_hash', 'last_restart_at', 'last_restart_type', 'last_online_at', 'restart_count'] as $column) {
            $fields[] = Field::state($column, 'Runtime status of the database.');
        }
        if (in_array($this->type, ['redis', 'keydb', 'dragonfly'], true)) {
            $fields[] = Field::derived('legacy_password_quoting', 'Coolify sets it for databases made by old versions.');
        }

        return $this->map = new FieldMap($this->config('table'), $fields, [$this->config('table') => ['environment_id']]);
    }

    /**
     * @return list<ChildRecords>
     */
    public function children(): array
    {
        return $this->children ??= [
            new EnvironmentVariables($this->config('variables')),
            new PersistentStorages($this->config('volumes')),
            new FileStorages,
            new ScheduledBackups,
            new Tags,
            new SecretManager,
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
        $properties['type'] = ['const' => $this->type];
        $properties['name'] = ['type' => 'string'];
        $properties['server'] = ['type' => 'string'];
        $properties['destination'] = ['type' => 'string'];
        foreach ($this->children() as $child) {
            $properties[$child->key()] = $child->schema();
        }
        ksort($properties);

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
    }

    public function find(Environment $environment, string $key): ?Model
    {
        return $this->modelClass()::query()->where('environment_id', $environment->id)->where('config_key', $key)->first();
    }

    public function findByUuid(Environment $environment, string $uuid): ?Model
    {
        return $this->modelClass()::query()->where('environment_id', $environment->id)->where('uuid', $uuid)->where('id', '!=', 0)->first();
    }

    public function unkeyed(Environment $environment): Collection
    {
        return $this->modelClass()::query()->where('environment_id', $environment->id)->whereNull('config_key')->where('id', '!=', 0)->orderBy('id')->get();
    }

    public function canonical(string $key, array $desired): array
    {
        $desired['name'] ??= $key;
        $desired = $this->dropMappedDefaults($this->fieldMap(), $desired);
        foreach ($this->children() as $child) {
            $value = $child->canonical($desired[$child->key()] ?? null);
            unset($desired[$child->key()]);
            if ($value !== null) {
                $desired[$child->key()] = $value;
            }
        }

        return $this->sorted($desired);
    }

    public function read(Model $resource, ApplyContext $ctx): array
    {
        $result = ['type' => $this->type, 'name' => $resource->name];
        $result += $this->readMappedFields($this->fieldMap(), [$this->config('table') => $resource]);
        $result += $this->readDestination($resource->destination);
        foreach ($this->children() as $child) {
            $value = $child->read($resource);
            if ($value !== null) {
                $result[$child->key()] = $value;
            }
        }

        return $this->sorted($result);
    }

    private function sorted(array $value): array
    {
        ksort($value);
        foreach (['credentials', 'config', 'public', 'ports', 'limits', 'health_check', 'settings', 'ssl'] as $group) {
            if (isset($value[$group]) && is_array($value[$group])) {
                ksort($value[$group]);
            }
        }

        return $value;
    }

    public function validate(string $key, array $desired, ApplyContext $ctx, ?Model $current = null): array
    {
        $base = "resources.{$key}";
        $errors = [];

        $server = $this->server($desired, $ctx);
        if ($server !== null && $this->destination($server, $desired['destination'] ?? null) === null) {
            $errors[] = new ConfigError("{$base}.destination", 'This server has more than one destination. Add the network name of one.');
        }

        $validator = Validator::make($desired, [
            'image' => ['nullable', 'string', new DockerImageFormat],
            'public.port' => 'nullable|integer|min:1|max:65535',
            'public.enabled' => 'boolean',
            'limits.memory_swappiness' => 'nullable|integer|min:0|max:100',
        ]);
        foreach ($validator->errors()->messages() as $path => $messages) {
            $errors[] = new ConfigError("{$base}.{$path}", $messages[0]);
        }

        foreach (($desired['env'] ?? []) as $name => $entry) {
            if (str_starts_with((string) ($entry['ref'] ?? ''), 'vault.')) {
                $errors[] = new ConfigError("{$base}.env.{$name}", 'vault references work on applications only.');
            }
        }
        foreach (array_values((array) ($desired['backups'] ?? [])) as $index => $backup) {
            $storage = $backup['s3_storage'] ?? null;
            if ($storage !== null && ! S3Storage::query()->where('team_id', $ctx->teamId)->where('name', $storage)->exists()) {
                $errors[] = new ConfigError("{$base}.backups.{$index}.s3_storage", "No S3 storage named \"{$storage}\" is in this team.");
            }
        }

        return $errors;
    }

    public function create(string $key, array $desired, ApplyContext $ctx): Model
    {
        $server = $this->server($desired, $ctx) ?? throw new LogicException("Server \"{$desired['server']}\" not found.");
        $destination = $this->destination($server, $desired['destination'] ?? null) ?? throw new LogicException('Destination not found.');

        /** @var Model $database */
        $database = ($this->config('create'))($ctx->environment->id, $destination);
        $database->forceFill(['config_key' => $key]);
        $this->fill($database, $key, $desired);
        $this->writeChildren($database, $desired, $ctx);

        return $database;
    }

    public function update(Model $resource, array $desired, ApplyContext $ctx): void
    {
        $server = $this->server($desired, $ctx) ?? throw new LogicException("Server \"{$desired['server']}\" not found.");
        $destination = $this->destination($server, $desired['destination'] ?? null) ?? throw new LogicException('Destination not found.');
        $resource->forceFill(['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()]);
        $this->fill($resource, (string) $resource->config_key, $desired);
        $this->writeChildren($resource, $desired, $ctx);
    }

    private function fill(Model $database, string $key, array $desired): void
    {
        $values = ['name' => $desired['name'] ?? $key];
        foreach ($this->fieldMap()->managed() as $field) {
            if ($field->path === null || in_array($field->path, $this->customPaths(), true)) {
                continue;
            }
            $values[$field->column] = FieldValues::toStored($field, FieldValues::cast($field->jsonType, data_get($desired, $field->path, $field->default)));
        }
        $database->forceFill($values);
        $database->save();
    }

    private function writeChildren(Model $database, array $desired, ApplyContext $ctx): void
    {
        foreach ($this->children() as $child) {
            $child->write($database, $desired[$child->key()] ?? null, $ctx);
        }
    }

    public function delete(Model $resource, bool $deleteData): void
    {
        DeleteResourceJob::dispatch($resource, deleteVolumes: $deleteData, deleteConnectedNetworks: true, deleteConfigurations: true, dockerCleanup: true)->afterCommit();
    }

    public function referenceValue(Model $resource, string $property): ?string
    {
        return match ($property) {
            'host' => $resource->uuid,
            'port' => $this->config('port') === null ? null : (string) $this->config('port'),
            'internal_url' => $resource->internal_db_url ?? null,
            default => null,
        };
    }

    public function destructiveFieldChange(string $path, mixed $from, mixed $to): bool
    {
        if (str_starts_with($path, 'credentials.')) {
            return true;
        }
        if ($path !== 'image') {
            return false;
        }

        return ! $this->sameMajorImage((string) ($from ?? $this->config('image')), (string) ($to ?? $this->config('image')));
    }

    private function sameMajorImage(string $from, string $to): bool
    {
        [$fromRepository, $fromTag] = $this->splitImage($from);
        [$toRepository, $toTag] = $this->splitImage($to);
        if ($fromRepository !== $toRepository) {
            return false;
        }
        $fromMajor = preg_match('/^v?(\d+)/', $fromTag, $match) ? $match[1] : null;
        $toMajor = preg_match('/^v?(\d+)/', $toTag, $match) ? $match[1] : null;

        return $fromMajor !== null && $fromMajor === $toMajor;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitImage(string $image): array
    {
        $image = explode('@', $image)[0];
        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');
        if ($colon === false || ($slash !== false && $colon < $slash)) {
            return [$image, 'latest'];
        }

        return [substr($image, 0, $colon), substr($image, $colon + 1)];
    }
}
