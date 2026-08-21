<?php

namespace App\Actions\Migration;

use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ConsolidateResourcesToLocalhost
{
    use AsAction;

    /**
     * Move every team resource onto the Coolify host destination (server id 0).
     *
     * @param  ?Server  $volumeSourceOverride  When set (e.g. old Coolify host), volumes are pulled from this server for resources already pointing at localhost.
     * @return list<array{uuid: string, name: string, status: string, error: string}>
     */
    public function handle(int $teamId, bool $copyVolumeData = true, ?Server $volumeSourceOverride = null): array
    {
        $localhost = Server::find(0);
        if (! $localhost) {
            throw new RuntimeException('Coolify host (server id 0) was not found.');
        }

        $destination = $localhost->destinations()
            ->first(fn ($destination): bool => $destination instanceof StandaloneDocker);

        if (! $destination instanceof StandaloneDocker) {
            throw new RuntimeException('Coolify host has no Standalone Docker destination.');
        }

        $results = [];
        foreach ($this->orderedResources($teamId) as $resource) {
            $uuid = (string) $resource->uuid;
            $name = (string) ($resource->name ?? $uuid);

            if ($resource instanceof StandalonePostgresql && (int) $resource->id === 0) {
                $results[] = ['uuid' => $uuid, 'name' => $name, 'status' => 'skipped', 'error' => 'coolify-db'];

                continue;
            }

            $currentServer = $resource instanceof Service
                ? $resource->server
                : $resource->destination?->server;

            $alreadyLocal = $currentServer && (int) $currentServer->id === 0;
            $sourceForVolumes = $alreadyLocal && $volumeSourceOverride
                ? $volumeSourceOverride
                : null;

            if ($alreadyLocal && ! $volumeSourceOverride) {
                $results[] = ['uuid' => $uuid, 'name' => $name, 'status' => 'skipped', 'error' => 'Already on localhost.'];

                continue;
            }

            try {
                ReassignResourceToDestination::run(
                    $resource,
                    $destination,
                    $copyVolumeData,
                    false,
                    $sourceForVolumes,
                );
                $results[] = ['uuid' => $uuid, 'name' => $name, 'status' => 'migrated', 'error' => ''];
            } catch (\Throwable $exception) {
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
}
