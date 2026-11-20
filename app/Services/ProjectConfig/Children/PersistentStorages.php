<?php

namespace App\Services\ProjectConfig\Children;

use App\Models\LocalPersistentVolume;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;

/**
 * Docker volumes and bind mounts, keyed by the volume name without the resource UUID.
 */
class PersistentStorages implements ChildRecords
{
    /**
     * @param  list<string>  $defaultVolumes  Keys of volumes that Coolify creates with the resource. They are not in the file and never removed.
     */
    public function __construct(private readonly array $defaultVolumes = []) {}

    public function key(): string
    {
        return 'storages';
    }

    public function map(): FieldMap
    {
        return new FieldMap('local_persistent_volumes', [
            Field::managed('name', 'storages'),
            Field::managed('mount_path', 'storages.*.mount'),
            Field::managed('host_path', 'storages.*.host_path'),
            Field::managed('is_preview_suffix_enabled', 'storages.*.preview_suffix', true, 'boolean'),
            Field::derived('container_id', 'Coolify sets it for Compose volumes.'),
            Field::derived('ignores_compose_driver_options', 'Coolify sets it from the Compose file.'),
        ], ['local_persistent_volumes' => ['resource_type', 'resource_id', 'standalone_sqlite_id']]);
    }

    public function read(Model $owner): ?array
    {
        $result = [];
        foreach ($this->rows($owner) as $volume) {
            $key = $this->keyFor($owner, $volume->name);
            if (in_array($key, $this->defaultVolumes, true)) {
                continue;
            }
            $entry = ['mount' => $volume->mount_path];
            if (filled($volume->host_path)) {
                $entry['host_path'] = $volume->host_path;
            }
            if (! $volume->is_preview_suffix_enabled) {
                $entry['preview_suffix'] = false;
            }
            if ($key === $volume->name) {
                $entry['volume_name'] = $volume->name;
            }
            ksort($entry);
            $result[$key] = $entry;
        }
        ksort($result);

        return $result === [] ? null : $result;
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || $desired === []) {
            return null;
        }
        foreach ($desired as $key => $entry) {
            if (($entry['preview_suffix'] ?? true) === true) {
                unset($entry['preview_suffix']);
            }
            if (($entry['host_path'] ?? null) === null) {
                unset($entry['host_path']);
            }
            ksort($entry);
            $desired[$key] = $entry;
        }
        ksort($desired);

        return $desired;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'required' => ['mount'],
                'additionalProperties' => false,
                'properties' => [
                    'mount' => ['type' => 'string'],
                    'host_path' => ['type' => ['string', 'null']],
                    'preview_suffix' => ['type' => 'boolean'],
                    'volume_name' => ['type' => 'string'],
                ],
            ],
        ];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        $desired = is_array($desired) ? $desired : [];
        $existing = [];
        foreach ($this->rows($owner) as $volume) {
            $existing[$this->keyFor($owner, $volume->name)] = $volume;
        }

        foreach ($desired as $key => $entry) {
            $volume = $existing[$key] ?? new LocalPersistentVolume([
                'resource_type' => $owner->getMorphClass(),
                'resource_id' => $owner->getKey(),
            ]);
            $volume->name = $entry['volume_name'] ?? ($volume->exists ? $volume->name : "{$owner->uuid}-{$key}");
            $volume->mount_path = $entry['mount'];
            $volume->host_path = $entry['host_path'] ?? null;
            $volume->is_preview_suffix_enabled = $entry['preview_suffix'] ?? true;
            $volume->save();
            unset($existing[$key]);
        }

        foreach ($existing as $key => $volume) {
            if (! in_array($key, $this->defaultVolumes, true)) {
                $volume->delete();
            }
        }
    }

    /**
     * The file key: the volume name without the owner's UUID. A name without the UUID stays as is.
     */
    public function keyFor(Model $owner, string $name): string
    {
        $uuid = (string) $owner->uuid;
        foreach (["{$uuid}-", "-{$uuid}", "{$uuid}_", "_{$uuid}"] as $part) {
            if (str_contains($name, $part)) {
                return str_replace($part, '', $name);
            }
        }

        return $name;
    }

    protected function rows(Model $owner)
    {
        return LocalPersistentVolume::query()
            ->where('resource_type', $owner->getMorphClass())
            ->where('resource_id', $owner->getKey())
            ->orderBy('id')
            ->get();
    }
}
