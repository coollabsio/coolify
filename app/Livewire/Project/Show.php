<?php

namespace App\Livewire\Project;

use App\Livewire\Concerns\CreatesEnvironments;
use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;
    use CreatesEnvironments;
    use RemembersCanvasView;

    public Project $project;

    public function mount(string $project_uuid)
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)
                ->where('uuid', $project_uuid)
                ->with([
                    'environments' => fn ($query) => $query
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
                            'sqlites',
                        ])
                        ->orderBy('created_at'),
                ])
                ->firstOrFail();

            if ($this->prefersCanvasView('project')) {
                return $this->redirectRoute('project.canvas', ['project_uuid' => $this->project->uuid], navigate: true);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function navigateToEnvironment($projectUuid, $environmentUuid)
    {
        return redirectRoute($this, 'project.resource.index', [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
        ]);
    }

    public function render(): View
    {
        $canUpdateProject = auth()->user()->can('update', $this->project);
        $canCreateResource = auth()->user()->can('createAnyResource');

        return view('livewire.project.show', [
            'environmentsJs' => $this->project->environments->sort(fn (Environment $first, Environment $second): int => Environment::compareNames($first->name, $second->name))->values()->map(function (Environment $environment) use ($canCreateResource, $canUpdateProject): array {
                $resourceCount = collect([
                    $environment->applications_count,
                    $environment->services_count,
                    $environment->postgresqls_count,
                    $environment->redis_count,
                    $environment->keydbs_count,
                    $environment->dragonflies_count,
                    $environment->clickhouses_count,
                    $environment->mongodbs_count,
                    $environment->mysqls_count,
                    $environment->mariadbs_count,
                    $environment->sqlites_count,
                ])->sum();

                return [
                    'uuid' => $environment->uuid,
                    'name' => $environment->name,
                    'description' => $environment->description,
                    'resourceCount' => $resourceCount,
                    'href' => route('project.resource.index', [
                        'project_uuid' => $this->project->uuid,
                        'environment_uuid' => $environment->uuid,
                    ]),
                    'settingsHref' => $canUpdateProject
                        ? route('project.environment.edit', [
                            'project_uuid' => $this->project->uuid,
                            'environment_uuid' => $environment->uuid,
                        ])
                        : null,
                    'addResourceHref' => $canCreateResource
                        ? route('project.resource.create', [
                            'project_uuid' => $this->project->uuid,
                            'environment_uuid' => $environment->uuid,
                        ])
                        : null,
                ];
            })->values()->toArray(),
        ]);
    }
}
