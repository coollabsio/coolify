<?php

namespace App\Actions\Migration;

use App\Actions\Database\RestoreDatabaseDump;
use App\Actions\Database\StartDatabase;
use App\Actions\Service\StartService;
use App\Enums\ResourceMigrationStatus;
use App\Jobs\VolumeRestoreJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\ResourceMigration;
use App\Models\ResourceMigrationItem;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\Migration\Manifest;
use App\Services\Migration\MigrationArchiver;
use App\Services\Migration\ResourceImporter;
use App\Services\Migration\Storage\MigrationStorageFactory;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use RuntimeException;
use Throwable;

class ImportResources
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
        $manifest = Manifest::fromArray($migration->manifest ?? []);
        $destination = $this->destination($migration);
        $environment = $this->environment($migration);
        $server = $destination->server;
        $storage = app(MigrationStorageFactory::class)->forMigration($migration);
        $archiver = new MigrationArchiver;
        $importer = new ResourceImporter;
        $uuidMap = [];

        try {
            if (! $migration->skip_data) {
                $this->assertDiskSpace($server, $storage, $manifest);
            }

            foreach ($manifest->resourcesInImportOrder() as $resource) {
                $item = $this->itemFor($migration, (string) $resource['source_uuid']);
                if (! $item) {
                    continue;
                }

                try {
                    $item->mark(ResourceMigrationStatus::Importing);
                    $created = $importer->import($resource, $destination, $environment, $uuidMap);
                    $item->update(['target_uuid' => $created->uuid]);

                    if (! $migration->skip_data) {
                        $this->restoreData($resource, $created, $item, $server, $storage, $archiver);
                    }

                    if (! $migration->skip_data) {
                        $item->mark(ResourceMigrationStatus::Deploying);
                        $this->deploy($created);
                        VerifyHealth::run(
                            $created,
                            $item,
                            attempts: app()->environment('testing') ? 1 : 12,
                            sleepSeconds: app()->environment('testing') ? 0 : 5,
                        );
                    } else {
                        $item->mark(ResourceMigrationStatus::Healthy);
                    }
                } catch (Throwable $exception) {
                    $item->mark(ResourceMigrationStatus::Failed, $exception->getMessage());
                }
            }

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

    private function destination(ResourceMigration $migration): StandaloneDocker|SwarmDocker
    {
        $destination = find_destination_for_team($migration->destination_uuid, $migration->team_id);
        if (! $destination) {
            throw new RuntimeException('Target destination was not found.');
        }
        if (! $destination->server?->canHostResources()) {
            throw new RuntimeException('The selected server cannot host resources.');
        }

        return $destination;
    }

    private function environment(ResourceMigration $migration): Environment
    {
        $project = Project::where('team_id', $migration->team_id)
            ->where('uuid', $migration->project_uuid)
            ->first();

        if (! $project) {
            throw new RuntimeException('Target project was not found.');
        }

        $environment = $project->environments()->where('uuid', $migration->environment_uuid)->first()
            ?? $project->environments()->first();

        if (! $environment) {
            throw new RuntimeException('Target environment was not found.');
        }

        return $environment;
    }

    private function itemFor(ResourceMigration $migration, string $sourceUuid): ?ResourceMigrationItem
    {
        return $migration->items->firstWhere('source_uuid', $sourceUuid);
    }

    private function assertDiskSpace(Server $server, mixed $storage, Manifest $manifest): void
    {
        $required = (int) ($manifest->totalArchiveBytes() * 1.1);
        if ($required <= 0) {
            return;
        }

        $free = $storage->diskFree($server);
        if ($free !== null && $free < $required) {
            throw new RuntimeException('Not enough disk space on the target server to restore migration archives.');
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function restoreData(
        array $resource,
        Model $created,
        ResourceMigrationItem $item,
        Server $server,
        mixed $storage,
        MigrationArchiver $archiver,
    ): void {
        $item->mark(ResourceMigrationStatus::Restoring);
        $restoredVolumes = 0;
        $base = backup_dir().'/migrations/'.$item->migration->uuid.'/'.$created->uuid;

        foreach ($resource['volumes'] ?? [] as $volume) {
            $archive = $volume['archive'] ?? null;
            if (! is_array($archive) || blank($archive['key'] ?? null)) {
                continue;
            }
            $localPath = $base.'/'.basename((string) $archive['key']);
            $storage->get($server, (string) $archive['key'], $localPath);
            $target = $created->persistentStorages()->where('mount_path', $volume['mount_path'])->first();
            if (! $target instanceof LocalPersistentVolume) {
                continue;
            }
            (new VolumeRestoreJob($localPath, $target->name, $server, $target))->handle();
            $restoredVolumes++;
        }

        foreach ($resource['file_storages'] ?? [] as $fileStorage) {
            $archive = $fileStorage['archive'] ?? null;
            if (! is_array($archive) || blank($archive['key'] ?? null) || blank($fileStorage['fs_path'] ?? null)) {
                continue;
            }
            $localPath = $base.'/'.basename((string) $archive['key']);
            $storage->get($server, (string) $archive['key'], $localPath);
            $archiver->restoreFileStorage($server, $localPath, (string) $fileStorage['fs_path']);
        }

        $dump = $resource['dump']['archive'] ?? null;
        if ($restoredVolumes === 0 && is_array($dump) && filled($dump['key'] ?? null)) {
            $this->deploy($created);
            $localPath = $base.'/dump';
            $storage->get($server, (string) $dump['key'], $localPath);
            RestoreDatabaseDump::run($created, $server, $localPath, (bool) ($resource['dump']['dump_all'] ?? false));
        }
    }

    private function deploy(Model $resource): void
    {
        if ($resource instanceof Application) {
            queue_application_deployment(
                application: $resource,
                deployment_uuid: new_public_id(),
                no_questions_asked: true,
                is_api: true,
            );

            return;
        }

        if ($resource instanceof Service) {
            StartService::run($resource);

            return;
        }

        StartDatabase::run($resource);
    }
}
