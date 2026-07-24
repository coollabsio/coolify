<?php

namespace App\Livewire\Railway;

use App\Models\Project;
use App\Support\RailwayResourceMapper;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.railway')]
class Projects extends Component
{
    public function render()
    {
        $projects = Project::ownedByCurrentTeamCached();

        $groups = $projects->map(function (Project $project): array {
            $environments = $project->environments
                ->sortBy(fn ($env) => $env->name === 'production' ? '0' : $env->name)
                ->map(function ($environment) use ($project): array {
                    $resources = RailwayResourceMapper::resourcesFor($environment);

                    return [
                        'uuid' => $environment->uuid,
                        'name' => $environment->name,
                        'total' => $resources->count(),
                        'online' => $resources->filter(fn ($r) => RailwayResourceMapper::isOnline($r))->count(),
                        'glyphs' => $resources->take(4)->map(fn ($r) => RailwayResourceMapper::iconType($r))->values()->all(),
                        'url' => route('railway.canvas', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $environment->uuid,
                        ]),
                    ];
                })->values();

            return [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'description' => $project->description,
                'environment_count' => $environments->count(),
                'environments' => $environments,
            ];
        })->values();

        return view('livewire.railway.projects', [
            'groups' => $groups,
        ]);
    }
}
