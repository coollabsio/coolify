<?php

namespace App\Services\Migration\Storage;

use App\Enums\MigrationStorageDriver as DriverEnum;
use App\Models\ResourceMigration;
use App\Models\S3Storage;
use InvalidArgumentException;

class MigrationStorageFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function make(DriverEnum $driver, array $config, int $teamId): MigrationStorageDriver
    {
        $config = $this->resolveS3Storage($config, $teamId);

        return match ($driver) {
            DriverEnum::S3 => new S3CompatibleDriver($config),
            DriverEnum::LocalSsh => new LocalSshDriver($config),
            DriverEnum::Azure => new AzureBlobDriver($config),
            DriverEnum::Gcs => new GcsDriver($config),
        };
    }

    public function forMigration(ResourceMigration $migration): MigrationStorageDriver
    {
        return $this->make(
            $migration->storage_driver,
            $migration->storage_config ?? [],
            $migration->team_id,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolveS3Storage(array $config, int $teamId): array
    {
        $uuid = $config['s3_storage_uuid'] ?? null;
        if (! is_string($uuid) || $uuid === '') {
            return $config;
        }

        $storage = S3Storage::where('team_id', $teamId)->where('uuid', $uuid)->first();
        if (! $storage) {
            throw new InvalidArgumentException('S3 storage not found for this team.');
        }

        return array_merge($config, [
            'endpoint' => $storage->endpoint,
            'bucket' => $storage->bucket,
            'region' => $storage->region,
            'key' => $storage->key,
            'secret' => $storage->secret,
        ]);
    }
}
