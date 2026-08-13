<?php

namespace App\Actions\Migration;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\Server;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class ReassignResourceToDestination
{
    use AsAction;

    public function handle(
        Model $resource,
        StandaloneDocker|SwarmDocker $destination,
        bool $copyVolumeData = true,
        bool $dispatchAsync = false,
        ?Server $volumeSourceServer = null,
    ): Model {
        if ($destination instanceof SwarmDocker) {
            throw new RuntimeException('Swarm destinations are not supported for instance consolidation.');
        }

        $sourceServer = $volumeSourceServer ?? $this->serverFor($resource);
        if (! $sourceServer) {
            throw new RuntimeException('Resource has no source server.');
        }

        $targetServer = $destination->server;
        $volumes = CollectResourceVolumes::run($resource);

        if ($copyVolumeData && $volumes !== [] && $sourceServer->id !== $targetServer->id) {
            $this->stopResource($resource);

            $jobs = [];
            foreach ($volumes as $volume) {
                $jobs[] = new VolumeCloneJob(
                    $volume->name,
                    $volume->name,
                    $sourceServer,
                    $targetServer,
                    $volume,
                );
            }

            if ($dispatchAsync) {
                Bus::chain($jobs)->onQueue('high')->dispatch();
            } else {
                foreach ($jobs as $job) {
                    dispatch_sync($job);
                }
            }
        }

        $this->updateDestination($resource, $destination);
        $resource->refresh();

        if (! $dispatchAsync) {
            try {
                $this->startResource($resource);
            } catch (Throwable) {
                // Destination was updated; deploy/start can be retried from the UI.
            }
        }

        return $resource;
    }

    private function serverFor(Model $resource): ?Server
    {
        if ($resource instanceof Service) {
            return $resource->server;
        }

        return $resource->destination?->server;
    }

    private function updateDestination(Model $resource, StandaloneDocker $destination): void
    {
        if ($resource instanceof Service) {
            $resource->destination_id = $destination->id;
            $resource->destination_type = $destination->getMorphClass();
            $resource->server_id = $destination->server_id;
            $resource->save();

            return;
        }

        if ($resource instanceof StandalonePostgresql && (int) $resource->id === 0) {
            throw new RuntimeException('Cannot reassign coolify-db.');
        }

        $resource->destination_id = $destination->id;
        $resource->destination_type = $destination->getMorphClass();
        $resource->save();
    }

    private function stopResource(Model $resource): void
    {
        if ($resource instanceof Application) {
            StopApplication::run($resource, false, false);

            return;
        }
        if ($resource instanceof Service) {
            StopService::run($resource, false, false);

            return;
        }
        if ($this->isStandaloneDatabase($resource)) {
            StopDatabase::run($resource, false);
        }
    }

    private function startResource(Model $resource): void
    {
        if ($resource instanceof Application) {
            queue_application_deployment(
                application: $resource,
                deployment_uuid: new_public_id(),
                force_rebuild: false,
            );

            return;
        }
        if ($resource instanceof Service) {
            StartService::run($resource);

            return;
        }
        if ($this->isStandaloneDatabase($resource)) {
            StartDatabase::run($resource);
        }
    }

    private function isStandaloneDatabase(Model $resource): bool
    {
        return $resource instanceof StandalonePostgresql
            || $resource instanceof StandaloneMongodb
            || $resource instanceof StandaloneMysql
            || $resource instanceof StandaloneMariadb
            || $resource instanceof StandaloneRedis
            || $resource instanceof StandaloneKeydb
            || $resource instanceof StandaloneDragonfly
            || $resource instanceof StandaloneClickhouse;
    }
}
