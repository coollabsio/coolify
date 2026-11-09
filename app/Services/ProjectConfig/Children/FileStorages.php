<?php

namespace App\Services\ProjectConfig\Children;

use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;

/**
 * Files and directories mounted into the container, keyed by mount path.
 */
class FileStorages implements ChildRecords
{
    private const DEFAULTS = ['is_directory' => false, 'is_host_file' => false, 'preview_suffix' => true];

    public function key(): string
    {
        return 'files';
    }

    public function map(): FieldMap
    {
        return new FieldMap('local_file_volumes', [
            Field::managed('mount_path', 'files'),
            Field::managed('content', 'files.*.content'),
            Field::managed('is_directory', 'files.*.is_directory', false, 'boolean'),
            Field::managed('is_host_file', 'files.*.is_host_file', false, 'boolean'),
            Field::managed('chown', 'files.*.chown'),
            Field::managed('chmod', 'files.*.chmod'),
            Field::managed('is_preview_suffix_enabled', 'files.*.preview_suffix', true, 'boolean'),
            Field::derived('fs_path', 'Coolify chooses the path on the server from the resource.'),
            Field::derived('is_based_on_git', 'Coolify sets it for files that come from the repository.'),
        ], ['local_file_volumes' => ['resource_type', 'resource_id']]);
    }

    public function read(Model $owner): ?array
    {
        $result = [];
        $rows = LocalFileVolume::query()
            ->where('resource_type', $owner->getMorphClass())
            ->where('resource_id', $owner->getKey())
            ->where('is_based_on_git', false)
            ->orderBy('id')
            ->get();
        foreach ($rows as $file) {
            $entry = [
                'content' => $file->is_directory ? null : $file->content,
                'is_directory' => (bool) $file->is_directory,
                'is_host_file' => (bool) $file->is_host_file,
                'chown' => $file->chown,
                'chmod' => $file->chmod,
                'preview_suffix' => (bool) $file->is_preview_suffix_enabled,
            ];
            $result[$file->mount_path] = $this->canonicalEntry($entry);
        }
        ksort($result);

        return $result === [] ? null : $result;
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || $desired === []) {
            return null;
        }
        foreach ($desired as $mount => $entry) {
            $desired[$mount] = $this->canonicalEntry($entry);
        }
        ksort($desired);

        return $desired;
    }

    private function canonicalEntry(array $entry): array
    {
        foreach ($entry as $key => $value) {
            if ($value === null || $value === '' || (array_key_exists($key, self::DEFAULTS) && self::DEFAULTS[$key] === $value)) {
                unset($entry[$key]);
            }
        }
        ksort($entry);

        return $entry;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'content' => ['type' => ['string', 'null']],
                    'is_directory' => ['type' => 'boolean'],
                    'is_host_file' => ['type' => 'boolean'],
                    'chown' => ['type' => ['string', 'null']],
                    'chmod' => ['type' => ['string', 'null']],
                    'preview_suffix' => ['type' => 'boolean'],
                ],
            ],
        ];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        $desired = is_array($desired) ? $desired : [];
        $existing = LocalFileVolume::query()
            ->where('resource_type', $owner->getMorphClass())
            ->where('resource_id', $owner->getKey())
            ->where('is_based_on_git', false)
            ->get()
            ->keyBy('mount_path');

        foreach ($desired as $mount => $entry) {
            $mount = validateFileMountPath((string) $mount, 'file mount path');
            $file = $existing[$mount] ?? new LocalFileVolume([
                'resource_type' => $owner->getMorphClass(),
                'resource_id' => $owner->getKey(),
                'mount_path' => $mount,
                'fs_path' => confineFileMountPath(self::hostPath($owner), $mount, 'file mount path'),
            ]);
            $isDirectory = (bool) ($entry['is_directory'] ?? false);
            $file->is_directory = $isDirectory;
            $file->content = $isDirectory ? null : ($entry['content'] ?? null);
            $file->is_host_file = (bool) ($entry['is_host_file'] ?? false);
            $file->chown = $entry['chown'] ?? null;
            $file->chmod = $entry['chmod'] ?? null;
            $file->is_preview_suffix_enabled = $entry['preview_suffix'] ?? true;
            $changed = $file->exists && $file->isDirty(['content', 'is_directory', 'chown', 'chmod']);
            $file->save();
            if ($changed && ! $file->is_host_file) {
                ServerStorageSaveJob::dispatch($file)->afterCommit();
            }
            unset($existing[$mount]);
        }

        foreach ($existing as $file) {
            $file->delete();
        }
    }

    /**
     * The folder of the resource on the server, as the storage page uses it.
     */
    public static function hostPath(Model $owner): string
    {
        if (method_exists($owner, 'workdir')) {
            return $owner->workdir();
        }
        if ($owner instanceof Application) {
            return application_configuration_dir().'/'.$owner->uuid;
        }

        return database_configuration_dir().'/'.$owner->uuid;
    }
}
