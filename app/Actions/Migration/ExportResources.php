<?php

namespace App\Actions\Migration;

use App\Enums\ResourceMigrationStatus;
use App\Models\ResourceMigration;
use App\Models\ResourceMigrationItem;
use App\Models\Server;
use App\Services\Migration\Manifest;
use App\Services\Migration\MigrationArchiver;
use App\Services\Migration\ResourceSerializer;
use App\Services\Migration\Storage\MigrationStorageFactory;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Throwable;

class ExportResources
{
    use AsAction;

    public int $jobTries = 1;

    public int $jobTimeout = 3600;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue('high');
    }

    public function handle(ResourceMigration $migration): void
    {
        $migration->markRunning();
        $serializer = new ResourceSerializer;
        $archiver = new MigrationArchiver;
        $storage = app(MigrationStorageFactory::class)->forMigration($migration);
        $resources = [];

        try {
            foreach ($migration->items as $item) {
                $item->mark(ResourceMigrationStatus::Exporting);
                $model = getResourceByUuid($item->source_uuid, $migration->team_id);
                if (! $model) {
                    $item->mark(ResourceMigrationStatus::Failed, 'Resource not found.');

                    continue;
                }

                $payload = $serializer->serialize($model);
                $server = $this->serverFor($model);

                if (! $migration->skip_data && $server) {
                    $payload = $this->archiveAndUpload($payload, $model, $server, $item, $archiver, $storage, $migration);
                }

                $item->update([
                    'archives' => $this->collectArchives($payload),
                    'status' => ResourceMigrationStatus::Uploaded,
                    'error' => null,
                ]);
                $resources[] = $payload;
            }

            $manifest = Manifest::make(
                storage: [
                    'driver' => $migration->storage_driver->value,
                    'config' => collect($migration->storage_config ?? [])->except(['key', 'secret', 'sas', 'credentials'])->all(),
                ],
                resources: $resources,
                skipData: $migration->skip_data,
            );

            $migration->update(['manifest' => $manifest->toArray()]);
            $migration->refreshAggregateStatus();
        } catch (Throwable $exception) {
            $migration->markFailed($exception->getMessage());

            throw $exception;
        }
    }

    public function asJob(JobDecorator $job, ResourceMigration $migration): void
    {
        $this->handle($migration);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function archiveAndUpload(
        array $payload,
        object $model,
        Server $server,
        ResourceMigrationItem $item,
        MigrationArchiver $archiver,
        mixed $storage,
        ResourceMigration $migration,
    ): array {
        $base = backup_dir().'/migrations/'.$migration->uuid.'/'.$item->source_uuid;

        foreach ($payload['volumes'] as $index => $volume) {
            $volumeModel = $model->persistentStorages()->where('name', $volume['name'])->first();
            if (! $volumeModel) {
                continue;
            }
            $path = $base.'/volume-'.$index.'.tar.gz';
            $archiver->archiveVolume($server, $volumeModel, $path);
            $key = $migration->uuid.'/'.$item->source_uuid.'/volume-'.$index.'.tar.gz';
            $payload['volumes'][$index]['archive'] = $storage->put($server, $path, $key);
        }

        foreach ($payload['file_storages'] as $index => $fileStorage) {
            if (! ($fileStorage['is_directory'] ?? false) && ! ($fileStorage['is_host_file'] ?? false)) {
                continue;
            }
            $fileModel = $model->fileStorages()->where('mount_path', $fileStorage['mount_path'])->first();
            if (! $fileModel || blank($fileModel->fs_path)) {
                continue;
            }
            $path = $base.'/file-'.$index.'.tar.gz';
            $archiver->archiveFileStorage($server, $fileModel, $path);
            $key = $migration->uuid.'/'.$item->source_uuid.'/file-'.$index.'.tar.gz';
            $payload['file_storages'][$index]['archive'] = $storage->put($server, $path, $key);
        }

        if ($archiver->supportsLogicalDump($model) && $model->persistentStorages()->count() === 0) {
            $path = $base.'/dump';
            $dump = $archiver->dumpDatabase($model, $server, $path);
            $key = $migration->uuid.'/'.$item->source_uuid.'/dump';
            $payload['dump'] = [
                'archive' => $storage->put($server, $path, $key),
                'dump_all' => $dump['dump_all'],
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function collectArchives(array $payload): array
    {
        $archives = [];
        foreach ($payload['volumes'] ?? [] as $volume) {
            if (is_array($volume['archive'] ?? null)) {
                $archives[] = $volume['archive'];
            }
        }
        foreach ($payload['file_storages'] ?? [] as $fileStorage) {
            if (is_array($fileStorage['archive'] ?? null)) {
                $archives[] = $fileStorage['archive'];
            }
        }
        if (is_array($payload['dump']['archive'] ?? null)) {
            $archives[] = $payload['dump']['archive'];
        }

        return $archives;
    }

    private function serverFor(object $resource): ?Server
    {
        if (isset($resource->server) && $resource->server instanceof Server) {
            return $resource->server;
        }

        return $resource->destination?->server;
    }
}
