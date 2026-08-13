<?php

namespace App\Services\Migration;

use Spatie\LaravelData\Data;

class Manifest extends Data
{
    public const VERSION = 1;

    /**
     * @param  array<int, array<string, mixed>>  $resources
     * @param  array<string, mixed>  $storage
     */
    public function __construct(
        public int $version,
        public string $exported_at,
        public string $source_version,
        public bool $skip_data,
        public array $storage,
        public array $resources,
    ) {}

    /**
     * @param  array<string, mixed>  $storage
     * @param  array<int, array<string, mixed>>  $resources
     */
    public static function make(array $storage, array $resources, bool $skipData = false): self
    {
        return new self(
            version: self::VERSION,
            exported_at: date('c'),
            source_version: self::coolifyVersion(),
            skip_data: $skipData,
            storage: $storage,
            resources: $resources,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            version: (int) ($payload['version'] ?? self::VERSION),
            exported_at: (string) ($payload['exported_at'] ?? date('c')),
            source_version: (string) ($payload['source_version'] ?? 'unknown'),
            skip_data: (bool) ($payload['skip_data'] ?? false),
            storage: is_array($payload['storage'] ?? null) ? $payload['storage'] : [],
            resources: is_array($payload['resources'] ?? null) ? $payload['resources'] : [],
        );
    }

    public function resourceCount(): int
    {
        return count($this->resources);
    }

    private static function coolifyVersion(): string
    {
        if (function_exists('app') && app()->bound('config')) {
            return (string) config('constants.coolify.version');
        }

        return 'unknown';
    }

    public function totalArchiveBytes(): int
    {
        $total = 0;

        foreach ($this->resources as $resource) {
            foreach ($resource['volumes'] ?? [] as $volume) {
                $total += (int) data_get($volume, 'archive.size_bytes', 0);
            }
            foreach ($resource['file_storages'] ?? [] as $fileStorage) {
                $total += (int) data_get($fileStorage, 'archive.size_bytes', 0);
            }
            $total += (int) data_get($resource, 'dump.archive.size_bytes', 0);
        }

        return $total;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resourcesInImportOrder(): array
    {
        $rank = function (array $resource): int {
            $type = (string) ($resource['type'] ?? '');

            if (str_starts_with($type, 'standalone')) {
                return 0;
            }

            if ($type === 'service') {
                return 1;
            }

            return 2;
        };

        $resources = $this->resources;
        usort($resources, fn (array $left, array $right): int => $rank($left) <=> $rank($right));

        return $resources;
    }
}
