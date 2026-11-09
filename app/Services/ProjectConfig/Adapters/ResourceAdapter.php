<?php

namespace App\Services\ProjectConfig\Adapters;

use App\Models\Environment;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigError;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Reads and writes one resource type in the file form of coolify.json.
 */
interface ResourceAdapter
{
    /** One of ConfigParser::TYPES. */
    public function type(): string;

    /** @return class-string<Model> */
    public function modelClass(): string;

    public function fieldMap(): FieldMap;

    /** @return array<string, FieldMap> child record name => its field map */
    public function childTables(): array;

    /** JSON schema of one resource of this type. */
    public function schema(): array;

    public function find(Environment $environment, string $key): ?Model;

    public function findByUuid(Environment $environment, string $uuid): ?Model;

    /** @return Collection<int, Model> resources without a config_key, never `id = 0` */
    public function unkeyed(Environment $environment): Collection;

    /** The desired entry in the same normalized form as read(): name filled, defaults dropped. */
    public function canonical(string $key, array $desired): array;

    /** The resource in file form, normalized, with `type`. */
    public function read(Model $resource, ApplyContext $ctx): array;

    /** @return list<ConfigError> */
    /** $current is the stored resource that the key matches (also through moved or import). */
    public function validate(string $key, array $desired, ApplyContext $ctx, ?Model $current = null): array;

    public function create(string $key, array $desired, ApplyContext $ctx): Model;

    public function update(Model $resource, array $desired, ApplyContext $ctx): void;

    public function delete(Model $resource, bool $deleteData): void;

    /** Value of `resource.<key>.<property>`: internal_url, host, or port. */
    public function referenceValue(Model $resource, string $property): ?string;

    public function destructiveFieldChange(string $path, mixed $from, mixed $to): bool;
}
