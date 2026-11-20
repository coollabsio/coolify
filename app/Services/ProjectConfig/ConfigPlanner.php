<?php

namespace App\Services\ProjectConfig;

use App\Enums\AccessLevel;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Services\Access\AccessResolver;
use App\Services\ProjectConfig\Adapters\ResourceAdapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Compares the desired state of one environment with what Coolify stores. Writes nothing.
 */
class ConfigPlanner
{
    private const REMOVAL_IS_DESTRUCTIVE = ['env', 'storages', 'files', 'scheduled_tasks'];

    public function __construct(private readonly AdapterRegistry $registry) {}

    public function plan(ResolvedEnvironment $env, ApplyContext $ctx): ConfigPlan
    {
        $errors = [];
        $changes = [];
        $stored = $this->storedResources($ctx);
        $stateHash = $this->stateHash($stored, $ctx);

        foreach ($env->moved as $index => $move) {
            if (! isset($stored[$move['from']])) {
                continue;
            }
            if (isset($stored[$move['to']])) {
                $errors[] = new ConfigError("moved.{$index}", "Both \"{$move['from']}\" and \"{$move['to']}\" exist. Remove one of them first.");

                continue;
            }
            $stored[$move['to']] = $stored[$move['from']];
            unset($stored[$move['from']]);
            $changes[] = new Change(ChangeKind::Rename, $move['to'], from: $move['from'], to: $move['to']);
        }

        $importedIds = [];
        foreach ($env->imports as $index => $import) {
            $path = "environments.{$env->name}.import.{$index}.uuid";
            $type = $env->resources[$import['to']]['type'] ?? null;
            if ($type === null || ! $this->registry->has($type)) {
                continue;
            }
            $adapter = $this->registry->for($type);
            $model = $adapter->findByUuid($ctx->environment, $import['uuid']);
            if ($model === null) {
                $errors[] = new ConfigError($path, "No {$type} with this UUID is in this environment.");

                continue;
            }
            if ($model->config_key === $import['to']) {
                continue;
            }
            if ($model->config_key !== null) {
                $errors[] = new ConfigError($path, "This resource is already managed as \"{$model->config_key}\".");

                continue;
            }
            if (isset($stored[$import['to']])) {
                $errors[] = new ConfigError("environments.{$env->name}.import.{$index}.to", "\"{$import['to']}\" already exists.");

                continue;
            }
            $stored[$import['to']] = [$adapter, $model];
            $importedIds[$model::class][] = $model->getKey();
            $changes[] = new Change(ChangeKind::Import, $import['to'], to: $import['uuid']);
        }

        foreach ($env->unmanage as $key) {
            if (isset($stored[$key])) {
                $changes[] = new Change(ChangeKind::Unmanage, $key);
                unset($stored[$key]);
            }
        }

        $missingRequired = [];
        foreach ($env->resources as $key => $desired) {
            $type = $desired['type'];
            if (! $this->registry->has($type)) {
                $errors[] = new ConfigError("resources.{$key}.type", "Coolify cannot manage \"{$type}\" resources from coolify.json yet.");

                continue;
            }
            $adapter = $this->registry->for($type);
            $current = $stored[$key] ?? null;
            $errors = [
                ...$errors,
                ...$this->unknownKeys($desired, $adapter->schema(), "resources.{$key}"),
                ...$this->checkServer($key, $desired, $ctx),
                ...$this->checkActor($key, $desired, $ctx),
                ...$adapter->validate($key, $desired, $ctx, $current[1] ?? null),
            ];

            $missing = $this->missingRequired($desired, $current[1] ?? null);
            if ($missing !== []) {
                $missingRequired[$key] = $missing;
            }

            if ($current === null) {
                $changes[] = new Change(ChangeKind::Create, $key);

                continue;
            }
            [$currentAdapter, $model] = $current;
            if ($currentAdapter->type() !== $type) {
                $changes[] = new Change(ChangeKind::Remove, $key, destructive: true, from: $currentAdapter->type());
                $changes[] = new Change(ChangeKind::Create, $key, to: $type);

                continue;
            }

            $differences = ConfigDiff::between($adapter->read($model, $ctx), $adapter->canonical($key, $desired));
            foreach ($differences as $difference) {
                $changes[] = $this->fieldChange($adapter, $key, $difference);
            }
        }

        foreach ($env->resources as $key => $desired) {
            foreach (($desired['env'] ?? []) as $name => $entry) {
                $ref = (string) ($entry['ref'] ?? '');
                $target = str_starts_with($ref, 'resource.') ? explode('.', $ref)[1] : null;
                if ($target !== null && ($env->resources[$target]['type'] ?? null) === 'service') {
                    $errors[] = new ConfigError("resources.{$key}.env.{$name}", 'Services have no single address. Reference a service domain with a value instead.');
                } elseif ($target !== null && $this->refHasNoValue($ref, $env->resources[$target] ?? null)) {
                    $errors[] = new ConfigError("resources.{$key}.env.{$name}", "\"{$target}\" has no ".explode('.', $ref)[2].'.');
                }
            }
        }

        foreach ($stored as $key => [$adapter]) {
            if (! array_key_exists($key, $env->resources)) {
                $changes[] = new Change(ChangeKind::Remove, $key, destructive: true, from: $adapter->type());
            }
        }

        return new ConfigPlan(
            changes: $changes,
            errors: $errors,
            warnings: $this->unmanagedWarnings($ctx, $importedIds),
            stateHash: $stateHash,
            missingRequired: $missingRequired,
            removesAll: $env->resources === [],
        );
    }

    /**
     * @param  array{path: string, from: mixed, to: mixed}  $difference
     */
    private function fieldChange(ResourceAdapter $adapter, string $key, array $difference): Change
    {
        $path = $difference['path'];
        $group = explode('.', $path)[0];
        $destructive = in_array($path, ['server', 'destination'], true)
            || (in_array($group, self::REMOVAL_IS_DESTRUCTIVE, true) && $difference['to'] === null && $path !== $group)
            || $adapter->destructiveFieldChange($path, $difference['from'], $difference['to']);

        return new Change(
            kind: ChangeKind::Update,
            key: $key,
            path: $path,
            destructive: $destructive,
            secret: $group === 'env',
            from: $group === 'env' ? null : $difference['from'],
            to: $group === 'env' ? null : $difference['to'],
        );
    }

    /**
     * Whether `resource.<key>.<property>` resolves to nothing: an application without an exposed port, or a database without a port (SQLite).
     */
    private function refHasNoValue(string $ref, ?array $target): bool
    {
        $property = explode('.', $ref)[2];
        if ($target === null || $property === 'host' || ! $this->registry->has($target['type'])) {
            return false;
        }
        if ($target['type'] === 'application') {
            return $property === 'port' && blank(explode(',', (string) ($target['ports']['exposes'] ?? ''))[0]);
        }
        $adapter = $this->registry->for($target['type']);

        return $adapter->referenceValue(new ($adapter->modelClass()), 'port') === null;
    }

    /**
     * Keyed resources of the environment, by key.
     *
     * @return array<string, array{0: ResourceAdapter, 1: Model}>
     */
    private function storedResources(ApplyContext $ctx): array
    {
        $stored = [];
        foreach ($this->registry->all() as $adapter) {
            $models = $adapter->modelClass()::query()
                ->where('environment_id', $ctx->environment->id)
                ->whereNotNull('config_key')
                ->where('id', '!=', 0)
                ->get();
            foreach ($models as $model) {
                $stored[$model->config_key] = [$adapter, $model];
            }
        }
        ksort($stored);

        return $stored;
    }

    /**
     * @param  array<string, array{0: ResourceAdapter, 1: Model}>  $stored
     */
    private function stateHash(array $stored, ApplyContext $ctx): string
    {
        $state = [];
        foreach ($stored as $key => [$adapter, $model]) {
            $state['resources'][$key] = $adapter->read($model, $ctx);
        }
        foreach ($this->registry->all() as $adapter) {
            foreach ($adapter->unkeyed($ctx->environment) as $model) {
                $state['unkeyed'][] = $model->uuid;
            }
        }
        if (isset($state['unkeyed'])) {
            sort($state['unkeyed']);
        }

        return hash('sha256', json_encode($state));
    }

    /**
     * @return list<ConfigError>
     */
    private function checkServer(string $key, array $desired, ApplyContext $ctx): array
    {
        $path = "resources.{$key}.server";
        $name = $desired['server'] ?? null;
        if (! is_string($name) || $name === '') {
            return [new ConfigError($path, 'Add the name of the server.')];
        }

        $servers = $this->serversNamed($name, $ctx);
        if ($servers->count() === 0) {
            return [new ConfigError($path, "No server is named \"{$name}\" in this team.")];
        }
        if ($servers->count() > 1) {
            return [new ConfigError($path, "More than one server is named \"{$name}\". Rename one of them.")];
        }
        if ($servers->first()->isTransferredAway()) {
            return [new ConfigError($path, "Server \"{$name}\" was transferred to another Coolify instance.")];
        }

        return [];
    }

    /**
     * A user who plans or applies a file needs the same rights as in the GUI: placing resources on each named
     * server, and team-wide write for team credentials (git sources, deploy keys, S3 storages, integration tokens).
     *
     * @return list<ConfigError>
     */
    private function checkActor(string $key, array $desired, ApplyContext $ctx): array
    {
        if ($ctx->actor === null) {
            return [];
        }
        $errors = [];
        $servers = ['server' => $desired['server'] ?? null];
        foreach (array_values((array) ($desired['additional_servers'] ?? [])) as $index => $entry) {
            $servers["additional_servers.{$index}.server"] = is_array($entry) ? ($entry['server'] ?? null) : $entry;
        }
        foreach ($servers as $path => $name) {
            $server = is_string($name) ? $this->serversNamed($name, $ctx)->first() : null;
            if ($server !== null && ! Gate::forUser($ctx->actor)->allows('placeResource', $server)) {
                $errors[] = new ConfigError("resources.{$key}.{$path}", 'You cannot place resources on this server.');
            }
        }

        $credentials = [];
        foreach (['github_app', 'gitea_app', 'deploy_key'] as $kind) {
            if (isset($desired['source'][$kind])) {
                $credentials[] = "source.{$kind}";
            }
        }
        if (isset($desired['secret_manager']['token'])) {
            $credentials[] = 'secret_manager.token';
        }
        foreach (array_values((array) ($desired['backups'] ?? [])) as $index => $backup) {
            if (isset($backup['s3_storage'])) {
                $credentials[] = "backups.{$index}.s3_storage";
            }
        }
        if ($credentials !== [] && ! app(AccessResolver::class)->allowsTeamWide($ctx->actor, AccessLevel::Write, $ctx->teamId)) {
            foreach ($credentials as $path) {
                $errors[] = new ConfigError("resources.{$key}.{$path}", 'Using team credentials needs write access to the whole team.');
            }
        }

        return $errors;
    }

    /**
     * Exact, case-sensitive name match in the team of the link.
     *
     * @return Collection<int, Server>
     */
    public function serversNamed(string $name, ApplyContext $ctx): Collection
    {
        return Server::query()->where('team_id', $ctx->teamId)->get()->filter(fn (Server $server) => $server->name === $name)->values();
    }

    /**
     * @param  array<string, list<int|string>>  $importedIds
     * @return list<string>
     */
    private function unmanagedWarnings(ApplyContext $ctx, array $importedIds): array
    {
        $warnings = [];
        foreach ($this->registry->all() as $adapter) {
            foreach ($adapter->unkeyed($ctx->environment) as $model) {
                if (in_array($model->getKey(), $importedIds[$model::class] ?? [], true)) {
                    continue;
                }
                $warnings[] = "\"{$model->name}\" ({$adapter->type()}) is not managed by coolify.json.";
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function missingRequired(array $desired, ?Model $model): array
    {
        $missing = [];
        foreach ($desired['env'] ?? [] as $name => $entry) {
            if (($entry['required'] ?? false) !== true) {
                continue;
            }
            $value = $model === null ? null : EnvironmentVariable::query()
                ->where('resourceable_type', $model->getMorphClass())
                ->where('resourceable_id', $model->getKey())
                ->where('key', $name)
                ->where('is_preview', false)
                ->first()?->value;
            if (blank($value)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @return list<ConfigError>
     */
    private function unknownKeys(mixed $value, array $schema, string $path): array
    {
        if (isset($schema['$ref']) || ! is_array($value)) {
            return [];
        }
        if (array_is_list($value)) {
            $items = $schema['items'] ?? null;
            if (! is_array($items)) {
                return [];
            }
            $errors = [];
            foreach ($value as $index => $item) {
                $errors = [...$errors, ...$this->unknownKeys($item, $items, "{$path}.{$index}")];
            }

            return $errors;
        }

        $errors = [];
        $properties = $schema['properties'] ?? [];
        $additional = $schema['additionalProperties'] ?? true;
        foreach ($value as $key => $item) {
            if (isset($properties[$key])) {
                $errors = [...$errors, ...$this->unknownKeys($item, $properties[$key], "{$path}.{$key}")];
            } elseif ($additional === false) {
                $errors[] = new ConfigError("{$path}.{$key}", 'Unknown key.');
            } elseif (is_array($additional)) {
                $errors = [...$errors, ...$this->unknownKeys($item, $additional, "{$path}.{$key}")];
            }
        }

        return $errors;
    }
}
