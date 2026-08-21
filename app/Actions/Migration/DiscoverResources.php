<?php

namespace App\Actions\Migration;

use App\Models\Application;
use App\Models\Project;
use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;

class DiscoverResources
{
    use AsAction;

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(
        int $teamId,
        ?string $serverUuid = null,
        ?string $projectUuid = null,
        ?string $environmentUuid = null,
    ): array {
        $projects = Project::where('team_id', $teamId)->get();
        $resources = collect();
        $resources->push($projects->pluck('applications')->flatten());
        $resources->push($projects->pluck('services')->flatten());
        foreach (collect(DATABASE_TYPES) as $db) {
            $resources->push($projects->pluck(str($db)->plural(2))->flatten());
        }

        return $resources->flatten()->filter()->map(function ($resource) use ($serverUuid, $projectUuid, $environmentUuid): ?array {
            $environment = $resource->environment;
            $project = $environment?->project;
            $server = $this->serverFor($resource);

            if ($projectUuid && $project?->uuid !== $projectUuid) {
                return null;
            }
            if ($environmentUuid && $environment?->uuid !== $environmentUuid) {
                return null;
            }
            if ($serverUuid && $server?->uuid !== $serverUuid) {
                return null;
            }

            $volumeCount = method_exists($resource, 'persistentStorages')
                ? $resource->persistentStorages()->count()
                : 0;

            return [
                'uuid' => $resource->uuid,
                'type' => $resource->type(),
                'name' => $resource->name,
                'status' => $resource->status,
                'server_uuid' => $server?->uuid,
                'server_name' => $server?->name,
                'project_uuid' => $project?->uuid,
                'environment_uuid' => $environment?->uuid,
                'volume_count' => $volumeCount,
                'warnings' => $this->warnings($resource),
            ];
        })->filter()->values()->all();
    }

    private function serverFor(object $resource): mixed
    {
        if ($resource instanceof Service) {
            return $resource->server;
        }

        return $resource->destination?->server;
    }

    /**
     * @return list<string>
     */
    private function warnings(object $resource): array
    {
        if (! $resource instanceof Application) {
            return [];
        }

        $warnings = [];
        if ($resource->source_id) {
            $warnings[] = 'Private GitHub App must exist on the target team.';
        }
        if ($resource->private_key_id) {
            $warnings[] = 'Deploy key must exist on the target team.';
        }

        return $warnings;
    }
}
