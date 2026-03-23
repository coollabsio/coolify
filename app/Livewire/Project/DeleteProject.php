<?php

namespace App\Livewire\Project;

use App\Jobs\DeleteResourceJob;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DeleteProject extends Component
{
    use AuthorizesRequests;

    public array $parameters;

    public int $project_id;

    public bool $disabled = false;

    public string $projectName = '';

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->projectName = Project::findOrFail($this->project_id)->name;
    }

    public function delete()
    {
        $this->validate([
            'project_id' => 'required|int',
        ]);
        $project = Project::findOrFail($this->project_id);
        $this->authorize('delete', $project);

        $resources = collect();
        foreach ($project->environments()->get() as $environment) {
            $resources = $resources
                ->concat($environment->applications()->get())
                ->concat($environment->databases())
                ->concat($environment->services()->get());
        }

        foreach ($resources as $resource) {
            if (! $resource) {
                continue;
            }
            $this->authorize('delete', $resource);
            $resource->delete();
            DeleteResourceJob::dispatch($resource, true, true, true, true);
        }

        $project->delete();

        return redirectRoute($this, 'project.index');
    }
}
