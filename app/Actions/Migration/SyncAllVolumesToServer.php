<?php

namespace App\Actions\Migration;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncAllVolumesToServer
{
    use AsAction;

    /**
     * Copy every resource volume onto the target server, keeping volume names.
     *
     * @return list<array{uuid: string, name: string, status: string, error: string}>
     */
    public function handle(int $teamId, Server $targetServer): array
    {
        $results = [];

        foreach ($this->orderedResources($teamId) as $resource) {
            $uuid = (string) $resource->uuid;
            $name = (string) ($resource->name ?? $uuid);

            if ($resource instanceof StandalonePostgresql && (int) $resource->id === 0) {
                $results[] = ['uuid' => $uuid, 'name' => $name, 'status' => 'skipped', 'error' => 'coolify-db'];

                continue;
            }

            $sourceServer = $resource instanceof Service
                ? $resource->server
                : $resource->destination?->server;

            if (! $sourceServer) {
                $results[] = ['uuid' => $uuid, 'name' => $name, 'status' => 'skipped', 'error' => 'No source server.'];

                continue;
            }

            if ((int) $sourceServer->id === (int) $targetServer->id) {
                $results[] = ['uuid' => $uuid, 'name' => $name, 'status' => 'skipped', 'error' => 'Already on target.'];

                continue;
            }

            $volumes = CollectResourceVolumes::run($resource);
            $volumes = array_values(array_filter(
                $volumes,
                fn ($volume) => ! self::isReservedVolumeName((string) $volume->name),
            ));
            if ($volumes === []) {
                $results[] = ['uuid' => $uuid, 'name' => $name, 'status' => 'skipped', 'error' => 'No volumes.'];

                continue;
            }

            try {
                $this->stopResource($resource);
                foreach ($volumes as $volume) {
                    dispatch_sync(new VolumeCloneJob(
                        $volume->name,
                        $volume->name,
                        $sourceServer,
                        $targetServer,
                        $volume,
                    ));
                }
                $this->startResource($resource);
                $results[] = ['uuid' => $uuid, 'name' => $name, 'status' => 'synced', 'error' => ''];
            } catch (\Throwable $exception) {
                try {
                    $this->startResource($resource);
                } catch (\Throwable) {
                    // ignore restart failure after sync error
                }
                $results[] = [
                    'uuid' => $uuid,
                    'name' => $name,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    public static function isReservedVolumeName(string $name): bool
    {
        return in_array($name, ['coolify-db', 'coolify-redis'], true);
    }

    /**
     * @return list<Model>
     */
    private function orderedResources(int $teamId): array
    {
        $projects = Project::where('team_id', $teamId)->get();
        $resources = collect();

        foreach (collect(DATABASE_TYPES) as $db) {
            $resources->push($projects->pluck(str($db)->plural(2))->flatten());
        }
        $resources->push($projects->pluck('services')->flatten());
        $resources->push($projects->pluck('applications')->flatten());

        return $resources->flatten()->filter()->values()->all();
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
        if (method_exists($resource, 'type') && str_starts_with((string) $resource->type(), 'standalone')) {
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
        if (method_exists($resource, 'type') && str_starts_with((string) $resource->type(), 'standalone')) {
            StartDatabase::run($resource);
        }
    }
}
