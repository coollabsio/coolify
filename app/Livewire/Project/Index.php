<?php

namespace App\Livewire\Project;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public $projects;

    public function mount(): void
    {
        // Only load what the page renders. Servers/private keys were previously
        // hydrated into public Livewire state but never used by the view.
        $this->projects = Project::ownedByCurrentTeam()
            ->with(['environments:id,uuid,name,project_id'])
            ->withCount([
                'applications',
                'services',
                'postgresqls',
                'redis',
                'keydbs',
                'dragonflies',
                'clickhouses',
                'mongodbs',
                'mysqls',
                'mariadbs',
            ])
            ->get();
    }

    public function render(): View
    {
        return view('livewire.project.index', [
            'projectsJs' => $this->projects->map(function (Project $project): array {
                $firstEnvironment = $project->environments->first();
                $resourceCount = collect([
                    $project->applications_count,
                    $project->services_count,
                    $project->postgresqls_count,
                    $project->redis_count,
                    $project->keydbs_count,
                    $project->dragonflies_count,
                    $project->clickhouses_count,
                    $project->mongodbs_count,
                    $project->mysqls_count,
                    $project->mariadbs_count,
                ])->sum();

                return [
                    'uuid' => $project->uuid,
                    'name' => $project->name,
                    'description' => $project->description,
                    'iconUrl' => $project->icon_path ? project_icon_url($project) : null,
                    'href' => $project->navigateTo(),
                    'environmentCount' => $project->environments->count(),
                    'resourceCount' => $resourceCount,
                    'settingsHref' => auth()->user()->can('update', $project)
                        ? route('project.edit', ['project_uuid' => $project->uuid])
                        : null,
                    'addResourceHref' => $firstEnvironment && auth()->user()->can('createAnyResource')
                        ? route('project.resource.create', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $firstEnvironment->uuid,
                        ])
                        : null,
                ];
            })->values()->toArray(),
        ]);
    }
}
