<?php

namespace App\Services\ProjectConfig\Children;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;

/**
 * Database backup schedules, as a list in file order.
 */
class ScheduledBackups implements ChildRecords
{
    /** file key => [column, default] */
    private const FIELDS = [
        'frequency' => ['frequency', null],
        'enabled' => ['enabled', true],
        'description' => ['description', null],
        'save_s3' => ['save_s3', true],
        'databases' => ['databases_to_backup', null],
        'dump_all' => ['dump_all', false],
        'timeout' => ['timeout', 3600],
        'disable_local_backup' => ['disable_local_backup', false],
        'retention_local_amount' => ['database_backup_retention_amount_locally', 0],
        'retention_local_days' => ['database_backup_retention_days_locally', 0],
        'retention_local_max_storage' => ['database_backup_retention_max_storage_locally', '0'],
        'retention_s3_amount' => ['database_backup_retention_amount_s3', 0],
        'retention_s3_days' => ['database_backup_retention_days_s3', 0],
        'retention_s3_max_storage' => ['database_backup_retention_max_storage_s3', '0'],
        'missing_backup_notification_days' => ['missing_backup_notification_days', '0'],
    ];

    public function key(): string
    {
        return 'backups';
    }

    public function map(): FieldMap
    {
        $fields = [];
        foreach (self::FIELDS as $path => [$column]) {
            $fields[] = Field::managed($column, "backups.*.{$path}");
        }

        return new FieldMap('scheduled_database_backups', [
            ...$fields,
            Field::managed('s3_storage_id', 'backups.*.s3_storage'),
            Field::derived('team_id', 'Coolify sets the team of the resource.'),
            Field::state('last_execution_at', 'Time of the last backup run.'),
        ], ['scheduled_database_backups' => ['database_type', 'database_id']]);
    }

    public function read(Model $owner): ?array
    {
        $result = [];
        foreach ($owner->scheduledBackups()->with('s3')->orderBy('id')->get() as $backup) {
            $entry = [];
            foreach (self::FIELDS as $path => [$column]) {
                $entry[$path] = $backup->{$column};
            }
            $entry['s3_storage'] = $backup->s3?->name;
            $result[] = $this->canonicalEntry($entry);
        }

        return $result === [] ? null : $result;
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || $desired === []) {
            return null;
        }

        return array_values(array_map(fn ($entry) => $this->canonicalEntry((array) $entry), $desired));
    }

    private function canonicalEntry(array $entry): array
    {
        foreach (self::FIELDS as $path => [, $default]) {
            if (! array_key_exists($path, $entry)) {
                continue;
            }
            $value = is_bool($default) ? (bool) $entry[$path] : (is_int($default) ? (int) $entry[$path] : ($entry[$path] === null ? null : (string) $entry[$path]));
            if ($value === $default || $value === null || $value === '') {
                unset($entry[$path]);
            } else {
                $entry[$path] = $value;
            }
        }
        if (($entry['s3_storage'] ?? null) === null) {
            unset($entry['s3_storage']);
        }
        ksort($entry);

        return $entry;
    }

    public function schema(): array
    {
        $properties = ['s3_storage' => ['type' => ['string', 'null']]];
        foreach (self::FIELDS as $path => [, $default]) {
            $properties[$path] = ['type' => is_bool($default) ? 'boolean' : (is_int($default) ? 'integer' : ['string', 'null'])];
        }
        ksort($properties);

        return ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['frequency'], 'additionalProperties' => false, 'properties' => $properties]];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        $desired = is_array($desired) ? array_values($desired) : [];
        $existing = $owner->scheduledBackups()->orderBy('id')->get()->values();

        foreach ($desired as $index => $entry) {
            $backup = $existing[$index] ?? new ScheduledDatabaseBackup;
            $attributes = [
                'database_type' => $owner->getMorphClass(),
                'database_id' => $owner->getKey(),
                'team_id' => $ctx->teamId,
                's3_storage_id' => isset($entry['s3_storage'])
                    ? S3Storage::query()->where('team_id', $ctx->teamId)->where('name', $entry['s3_storage'])->value('id')
                    : null,
            ];
            foreach (self::FIELDS as $path => [$column, $default]) {
                $attributes[$column] = $entry[$path] ?? $default;
            }
            $backup->forceFill($attributes)->save();
        }

        foreach ($existing->slice(count($desired)) as $backup) {
            $backup->delete();
        }
    }
}
