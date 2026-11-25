<?php

namespace App\Services\ProjectConfig\Children;

use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Models\LocalPersistentVolume;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigError;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use App\Services\ProjectConfig\NamedCredentials;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Backup schedules of Docker volumes, keyed by the volume name without the resource UUID.
 * A Compose service uses the volume names of its Compose file. One schedule per volume.
 */
class StorageBackups implements ChildRecords
{
    /** file key => [column, default, JSON type] */
    private const FIELDS = [
        'frequency' => ['frequency', null, 'string'],
        'enabled' => ['enabled', true, 'boolean'],
        'save_s3' => ['save_s3', false, 'boolean'],
        'disable_local_backup' => ['disable_local_backup', false, 'boolean'],
        'stop_during_backup' => ['stop_during_backup', false, 'boolean'],
        'retention_local_amount' => ['retention_amount_locally', 7, 'integer'],
        'retention_local_days' => ['retention_days_locally', 0, 'integer'],
        'retention_local_max_storage' => ['retention_max_storage_locally', 0.0, 'number'],
        'retention_s3_amount' => ['retention_amount_s3', 7, 'integer'],
        'retention_s3_days' => ['retention_days_s3', 0, 'integer'],
        'retention_s3_max_storage' => ['retention_max_storage_s3', 0.0, 'number'],
        'timeout' => ['timeout', ScheduledVolumeBackup::DEFAULT_TIMEOUT, 'integer'],
        'missing_backup_notification_days' => ['missing_backup_notification_days', 0, 'integer'],
    ];

    /** file key => [minimum, maximum], the same limits as the API */
    private const RANGES = [
        'retention_local_amount' => [0, 10000],
        'retention_local_days' => [0, 2147483647],
        'retention_local_max_storage' => [0, 9999999999],
        'retention_s3_amount' => [0, 10000],
        'retention_s3_days' => [0, 2147483647],
        'retention_s3_max_storage' => [0, 9999999999],
        'timeout' => [60, 36000],
        'missing_backup_notification_days' => [0, 365],
    ];

    public function key(): string
    {
        return 'storage_backups';
    }

    /**
     * A file without the key leaves the schedules to the dashboard and the API. An empty object removes them all.
     */
    public static function managedBy(array $desired): bool
    {
        return array_key_exists('storage_backups', $desired);
    }

    public function map(): FieldMap
    {
        $fields = [];
        foreach (self::FIELDS as $path => [$column, $default, $jsonType]) {
            $fields[] = Field::managed($column, "storage_backups.*.{$path}", $default, $jsonType);
        }

        return new FieldMap('scheduled_volume_backups', [
            ...$fields,
            Field::managed('s3_storage_id', 'storage_backups.*.s3_storage'),
            Field::derived('team_id', 'Coolify sets the team of the resource.'),
            Field::state('last_execution_at', 'Time of the last backup run.'),
        ], ['scheduled_volume_backups' => ['backupable_type', 'backupable_id']]);
    }

    public function read(Model $owner): ?array
    {
        $result = [];
        foreach ($this->volumes($owner) as $key => $rows) {
            $backup = $rows->first()->scheduledBackups()->with(['s3', 's3Storages'])->first();
            if ($backup === null) {
                continue;
            }
            $entry = [];
            foreach (self::FIELDS as $path => [$column]) {
                $entry[$path] = $backup->{$column};
            }
            $names = $backup->selectedS3Storages()->pluck('name')->all();
            $entry['s3_storage'] = count($names) > 1 ? $names : ($names[0] ?? null);
            $result[$key] = $this->canonicalEntry($entry);
        }
        ksort($result);

        return $result === [] ? null : $result;
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || $desired === []) {
            return null;
        }
        $result = [];
        foreach ($desired as $key => $entry) {
            $result[(string) $key] = $this->canonicalEntry((array) $entry);
        }
        ksort($result);

        return $result;
    }

    private function canonicalEntry(array $entry): array
    {
        foreach (self::FIELDS as $path => [, $default, $jsonType]) {
            if (! array_key_exists($path, $entry)) {
                continue;
            }
            $value = $entry[$path] === null ? null : match ($jsonType) {
                'boolean' => (bool) $entry[$path],
                'integer' => (int) $entry[$path],
                'number' => (float) $entry[$path],
                default => (string) $entry[$path],
            };
            if ($value === null || $value === '' || $value === $default) {
                unset($entry[$path]);
            } else {
                $entry[$path] = $value;
            }
        }
        $storages = array_values(array_unique(array_map('strval', (array) ($entry['s3_storage'] ?? []))));
        if ($storages === []) {
            unset($entry['s3_storage']);
        } else {
            $entry['s3_storage'] = count($storages) === 1 ? $storages[0] : [$storages[0], ...$this->sorted(array_slice($storages, 1))];
        }
        ksort($entry);

        return $entry;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    public function schema(): array
    {
        $properties = [
            's3_storage' => [
                'description' => 'Name of the S3 storage, or a list of names. The first one is the primary destination.',
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'uniqueItems' => true],
                ],
            ],
        ];
        foreach (self::FIELDS as $path => [, , $jsonType]) {
            $properties[$path] = ['type' => $jsonType];
            if (isset(self::RANGES[$path])) {
                [$properties[$path]['minimum'], $properties[$path]['maximum']] = self::RANGES[$path];
            }
        }
        ksort($properties);

        return [
            'type' => 'object',
            'description' => 'Backup schedules of Docker volumes, keyed by volume name. One schedule per volume.',
            'additionalProperties' => ['type' => 'object', 'required' => ['frequency'], 'additionalProperties' => false, 'properties' => $properties],
        ];
    }

    /**
     * @param  list<string>|null  $knownVolumes  Volume keys that exist after the apply, or null when they are known only after a deployment (Compose applications).
     * @return list<ConfigError>
     */
    public static function errors(string $base, mixed $desired, int $teamId, ?array $knownVolumes): array
    {
        $errors = [];
        foreach ((array) $desired as $key => $entry) {
            $path = "{$base}.storage_backups.{$key}";
            $entry = (array) $entry;
            if ($knownVolumes !== null && ! in_array((string) $key, $knownVolumes, true)) {
                $errors[] = new ConfigError($path, 'The resource has no volume with this name.');
            }
            if (! validate_cron_expression((string) ($entry['frequency'] ?? ''))) {
                $errors[] = new ConfigError("{$path}.frequency", 'Use a valid cron or human expression.');
            }
            $saveS3 = (bool) ($entry['save_s3'] ?? false);
            if (($entry['disable_local_backup'] ?? false) && ! $saveS3) {
                $errors[] = new ConfigError("{$path}.disable_local_backup", 'Local backups can only be disabled when S3 backups are enabled.');
            }
            $storages = (array) ($entry['s3_storage'] ?? []);
            if ($saveS3 && $storages === []) {
                $errors[] = new ConfigError("{$path}.s3_storage", 'Name an S3 storage when save_s3 is true.');
            }
            foreach ($storages as $name) {
                $message = NamedCredentials::error('s3_storage', (string) $name, $teamId);
                if ($message === null && ! NamedCredentials::find('s3_storage', (string) $name, $teamId)->is_usable) {
                    $message = "The S3 storage \"{$name}\" is not usable. Test its connection in Coolify.";
                }
                if ($message !== null) {
                    $errors[] = new ConfigError("{$path}.s3_storage", $message);
                }
            }
            foreach (self::RANGES as $field => [$min, $max]) {
                if (! array_key_exists($field, $entry)) {
                    continue;
                }
                $value = $entry[$field];
                $isNumber = self::FIELDS[$field][2] === 'integer' ? is_int($value) : (is_int($value) || is_float($value));
                if (! $isNumber || $value < $min || $value > $max) {
                    $errors[] = new ConfigError("{$path}.{$field}", "Use a number from {$min} to {$max}.");
                }
            }
        }

        return $errors;
    }

    /**
     * Credential paths that need write access to the whole team.
     *
     * @return list<string>
     */
    public static function credentialPaths(mixed $desired): array
    {
        $paths = [];
        foreach ((array) $desired as $key => $entry) {
            if (isset($entry['s3_storage'])) {
                $paths[] = "storage_backups.{$key}.s3_storage";
            }
        }

        return $paths;
    }

    /**
     * Removes the schedules that the file no longer has. It runs before the storages are written,
     * because a volume with a schedule cannot be deleted. The archive files stay on the server and in S3,
     * but Coolify no longer lists them, as when a schedule is deleted in the dashboard without its archives.
     */
    public function prune(Model $owner, mixed $desired): void
    {
        $desired = is_array($desired) ? $desired : [];
        foreach ($this->volumes($owner) as $key => $rows) {
            if (! array_key_exists($key, $desired)) {
                $this->deleteSchedules($rows);
            }
        }
    }

    /**
     * Creates or updates the schedules. It runs after the volumes exist. A volume that does not exist yet
     * (a Compose application before its first deployment) is skipped, and the next apply adds its schedule.
     */
    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        $desired = is_array($desired) ? $desired : [];
        $this->prune($owner, $desired);
        $volumes = $this->volumes($owner);

        foreach ($desired as $key => $entry) {
            $rows = $volumes[(string) $key] ?? null;
            if ($rows === null) {
                continue;
            }
            $entry = (array) $entry;
            $storageIds = [];
            foreach ((array) ($entry['s3_storage'] ?? []) as $name) {
                $storage = NamedCredentials::find('s3_storage', (string) $name, $ctx->teamId);
                if (! $storage instanceof S3Storage) {
                    throw new LogicException("S3 storage \"{$name}\" not found.");
                }
                $storageIds[] = $storage->id;
            }
            $saveS3 = (bool) ($entry['save_s3'] ?? false);

            $attributes = ['team_id' => $ctx->teamId, 's3_storage_id' => $saveS3 ? ($storageIds[0] ?? null) : null];
            foreach (self::FIELDS as $path => [$column, $default]) {
                $attributes[$column] = $entry[$path] ?? $default;
            }
            $attributes['disable_local_backup'] = $saveS3 && $attributes['disable_local_backup'];

            $backup = $rows->first()->scheduledBackups()->updateOrCreate([], $attributes);
            $backup->syncS3Storages($saveS3 ? $storageIds : []);
            // One schedule per volume: the other containers that mount the same volume lose theirs.
            $this->deleteSchedules($rows->slice(1));
        }
    }

    /**
     * Plan warnings for the schedules of a Compose application whose volumes do not exist yet. The volumes of a
     * Compose application are known only after a deployment, so a schedule for a volume that does not exist yet
     * is added at the first apply after a deployment creates it. A name that no deployment creates never gets one.
     *
     * @return list<string>
     */
    public function pendingVolumeWarnings(string $key, array $desired, ?Model $current): array
    {
        if (($desired['type'] ?? null) !== 'application' || ($desired['build']['pack'] ?? null) !== 'dockercompose') {
            return [];
        }
        $existing = $current === null ? collect() : $this->volumes($current);
        $warnings = [];
        foreach (array_keys((array) ($desired['storage_backups'] ?? [])) as $volume) {
            if (! $existing->has((string) $volume)) {
                $warnings[] = "resources.{$key}.storage_backups.{$volume}: the application has no volume with this name yet. Coolify adds the schedule at the first apply after a deployment creates the volume.";
            }
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, LocalPersistentVolume>  $rows
     */
    private function deleteSchedules(Collection $rows): void
    {
        foreach ($rows as $volume) {
            foreach ($volume->scheduledBackups()->get() as $backup) {
                DeleteScheduledVolumeBackup::run($backup, deleteLocalArchives: false, deleteS3Archives: false);
            }
        }
    }

    /**
     * The volumes that can have a schedule, by file key. In a Compose service, several containers can mount the
     * same volume, so each key has a list of rows. The first row holds the schedule: a row that has one, or else the oldest row.
     *
     * @return Collection<string, Collection<int, LocalPersistentVolume>>
     */
    public function volumes(Model $owner): Collection
    {
        $owners = $owner instanceof Service ? $owner->applications()->get()->concat($owner->databases()->get()) : collect([$owner]);
        $keys = new PersistentStorages;
        $rows = collect();
        foreach ($owners as $resource) {
            $rows = $rows->concat(LocalPersistentVolume::query()
                ->where('resource_type', $resource->getMorphClass())
                ->where('resource_id', $resource->getKey())
                ->withExists('scheduledBackups')
                ->orderBy('id')
                ->get());
        }

        return $rows
            ->groupBy(fn (LocalPersistentVolume $volume) => $keys->keyFor($owner, $volume->name))
            ->map(fn (Collection $group) => $group->sortBy([['scheduled_backups_exists', 'desc'], ['id', 'asc']])->values());
    }
}
