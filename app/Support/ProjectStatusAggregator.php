<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class ProjectStatusAggregator
{
    private const RELATIONS = [
        'applications',
        'services.applications',
        'services.databases',
        'postgresqls',
        'redis',
        'keydbs',
        'dragonflies',
        'clickhouses',
        'mongodbs',
        'mysqls',
        'mariadbs',
    ];

    public static function forProjects(Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $projectIds = $projects->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        $cacheKey = 'project-dashboard-status:v1:'.md5($projectIds->implode(','));

        return Cache::remember($cacheKey, 5, function () use ($projectIds): array {
            $loaded = Project::query()
                ->whereIn('id', $projectIds)
                ->with(self::RELATIONS)
                ->get();

            return $loaded->mapWithKeys(function (Project $project): array {
                return [$project->uuid => self::forLoadedProject($project)];
            })->all();
        });
    }

    private static function forLoadedProject(Project $project): array
    {
        $statuses = collect();
        $resourceCount = 0;

        foreach (['applications', 'postgresqls', 'redis', 'keydbs', 'dragonflies', 'clickhouses', 'mongodbs', 'mysqls', 'mariadbs'] as $relation) {
            $resources = $project->{$relation};
            $resourceCount += $resources->count();

            foreach ($resources as $resource) {
                self::pushStatus($statuses, data_get($resource, 'status'));
            }
        }

        $resourceCount += $project->services->count();
        foreach ($project->services as $service) {
            self::pushStatus($statuses, $service->status);
        }

        if ($resourceCount === 0) {
            return ['label' => 'Empty', 'type' => 'neutral'];
        }

        $hasUnhealthyRunning = $statuses->contains(function (string $status): bool {
            return str_starts_with($status, 'degraded')
                || (str_starts_with($status, 'running') && str_contains($status, 'unhealthy'));
        });
        if ($hasUnhealthyRunning) {
            return ['label' => 'Unhealthy', 'type' => 'error'];
        }

        $hasStarting = $statuses->contains(function (string $status): bool {
            return str_starts_with($status, 'starting')
                || str_starts_with($status, 'restarting')
                || str_starts_with($status, 'queued');
        });
        if ($hasStarting) {
            return ['label' => 'Starting', 'type' => 'warning'];
        }

        if ($statuses->contains(fn (string $status): bool => str_starts_with($status, 'running'))) {
            return ['label' => 'Running', 'type' => 'success'];
        }

        return ['label' => 'Stopped', 'type' => 'neutral'];
    }

    private static function pushStatus(Collection $statuses, mixed $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status !== '') {
            $statuses->push($status);
        }
    }
}
