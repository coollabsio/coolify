<?php

namespace App\Livewire\Project;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public $projects;

    public $servers;

    public $private_keys;

    public function mount(): void
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeamCached();
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
        $this->servers = Server::ownedByCurrentTeamCached();
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
