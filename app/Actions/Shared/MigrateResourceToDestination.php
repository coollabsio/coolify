<?php

namespace App\Actions\Shared;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StopService;
use App\Jobs\FinalizeResourceMigrationJob;
use App\Jobs\HostPathCloneJob;
use App\Jobs\ServerStorageSaveJob;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\LocalPersistentVolume;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class MigrateResourceToDestination
{
    use AsAction;

    /**
     * @return array{async: bool, volume_jobs: int, message: string}
     */
    public function handle(
        Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
        StandaloneDocker|SwarmDocker $destination,
        bool $migrateVolumes = true,
    ): array {
        if (! isDev()) {
            throw ValidationException::withMessages([
                'destination_id' => 'Resource migration is only available in development mode.',
            ]);
        }

        $resource->loadMissing(['destination.server']);
        $sourceDestination = $resource->destination;

        if (! $sourceDestination) {
            throw ValidationException::withMessages([
                'destination_id' => 'Resource has no destination to migrate from.',
            ]);
        }

        if (
            (int) $sourceDestination->id === (int) $destination->id
            && $sourceDestination->getMorphClass() === $destination->getMorphClass()
        ) {
            throw ValidationException::withMessages([
                'destination_id' => 'Resource is already on the selected destination.',
            ]);
        }

        $sourceServer = $sourceDestination->server;
        $targetServer = $destination->server;

        if (! $targetServer) {
            throw ValidationException::withMessages([
                'destination_id' => 'Target destination has no server.',
            ]);
        }

        if (! $targetServer->canHostResources()) {
            throw ValidationException::withMessages([
                'destination_id' => 'The selected server cannot host resources.',
            ]);
        }

        $targetServer->refresh();
        if (! $targetServer->isFunctional()) {
            throw ValidationException::withMessages([
                'destination_id' => 'Target server is not validated and reachable.',
            ]);
        }

        $crossServer = $sourceServer && (int) $sourceServer->id !== (int) $targetServer->id;

        if (! $crossServer) {
            throw ValidationException::withMessages([
                'destination_id' => 'Migration requires a different server. Choose another server destination.',
            ]);
        }

        if ($migrateVolumes) {
            if (! $sourceServer?->isFunctional()) {
                throw ValidationException::withMessages([
                    'destination_id' => 'Source server is not functional. Cannot migrate volume data.',
                ]);
            }
        }

        $this->stopResource($resource);

        $jobs = [];
        if ($migrateVolumes) {
            $jobs = $this->buildVolumeJobs($resource, $sourceServer, $targetServer);
        }

        if ($jobs !== []) {
            Bus::chain([
                ...$jobs,
                new FinalizeResourceMigrationJob($resource, $destination),
            ])->dispatch();

            return [
                'async' => true,
                'volume_jobs' => count($jobs),
                'message' => 'Migration started. The resource was stopped and volume data is being transferred. Destination will update when transfer completes. Redeploy afterwards.',
            ];
        }

        $this->applyDestination($resource, $destination);

        return [
            'async' => false,
            'volume_jobs' => 0,
            'message' => $migrateVolumes
                ? 'Resource migrated to the new server. Redeploy when ready.'
                : 'Resource migrated to the new server. Volume data was not transferred. Redeploy when ready.',
        ];
    }

    public function applyDestination(
        Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
        StandaloneDocker|SwarmDocker $destination,
    ): void {
        $payload = [
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ];

        if ($resource instanceof Service) {
            $payload['server_id'] = $destination->server_id;
        } else {
            // Service status is computed from child containers, not a DB column.
            $payload['status'] = 'exited';
            $payload['started_at'] = null;
        }

        $resource->fill($payload)->save();

        if ($resource instanceof Application) {
            $resource->additional_networks()->detach();
            $this->regenerateApplicationLabels($resource->fresh(['destination.server', 'settings']));
        }

        if ($resource instanceof Service) {
            foreach ($resource->applications() as $application) {
                $application->fill(['status' => 'exited'])->save();
            }
            foreach ($resource->databases() as $database) {
                $database->fill(['status' => 'exited'])->save();
            }
        }

        $this->resaveFileStorages($resource->fresh());
    }

    protected function stopResource(
        Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
    ): void {
        try {
            if ($resource instanceof Application) {
                StopApplication::run($resource, previewDeployments: false, dockerCleanup: false);
            } elseif ($resource instanceof Service) {
                StopService::run($resource, deleteConnectedNetworks: false, dockerCleanup: false);
            } else {
                StopDatabase::run($resource, dockerCleanup: false);
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to stop resource during migration: '.$e->getMessage(), [
                'resource_type' => $resource->getMorphClass(),
                'resource_uuid' => $resource->uuid ?? null,
            ]);
        }
    }

    /**
     * @return array<int, VolumeCloneJob|HostPathCloneJob>
     */
    protected function buildVolumeJobs(
        Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
        $sourceServer,
        $targetServer,
    ): array {
        $jobs = [];
        $seenNamedVolumes = [];
        $seenHostPaths = [];

        foreach ($this->collectPersistentVolumes($resource) as $volume) {
            if (! $volume instanceof LocalPersistentVolume) {
                continue;
            }

            $hostPath = filled($volume->host_path) ? (string) $volume->host_path : null;

            if ($hostPath) {
                if (isset($seenHostPaths[$hostPath])) {
                    continue;
                }
                $seenHostPaths[$hostPath] = true;
                $jobs[] = new HostPathCloneJob($hostPath, $hostPath, $sourceServer, $targetServer);

                continue;
            }

            $name = (string) $volume->name;
            if ($name === '' || isset($seenNamedVolumes[$name])) {
                continue;
            }
            $seenNamedVolumes[$name] = true;
            $jobs[] = new VolumeCloneJob($name, $name, $sourceServer, $targetServer, $volume);
        }

        return $jobs;
    }

    /**
     * @return Collection<int, LocalPersistentVolume>
     */
    protected function collectPersistentVolumes(
        Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
    ) {
        if ($resource instanceof Service) {
            $volumes = collect();
            foreach ($resource->applications() as $application) {
                $volumes = $volumes->merge($application->persistentStorages()->get());
            }
            foreach ($resource->databases() as $database) {
                $volumes = $volumes->merge($database->persistentStorages()->get());
            }

            return $volumes;
        }

        return $resource->persistentStorages()->get();
    }

    protected function regenerateApplicationLabels(Application $application): void
    {
        $settings = $application->settings;
        if (! $settings || ! $settings->is_container_label_readonly_enabled) {
            return;
        }

        if ($application->destination?->server?->proxyType() === 'NONE') {
            return;
        }

        $customLabels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
        $application->custom_labels = base64_encode($customLabels);
        $application->save();
    }

    protected function resaveFileStorages(
        Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
    ): void {
        $fileStorages = collect();

        if ($resource instanceof Service) {
            foreach ($resource->applications() as $application) {
                $fileStorages = $fileStorages->merge($application->fileStorages()->get());
            }
            foreach ($resource->databases() as $database) {
                $fileStorages = $fileStorages->merge($database->fileStorages()->get());
            }
        } elseif (method_exists($resource, 'fileStorages')) {
            $fileStorages = $resource->fileStorages()->get();
        }

        foreach ($fileStorages as $storage) {
            if ($storage->is_host_file) {
                continue;
            }
            ServerStorageSaveJob::dispatch($storage);
        }
    }
}
