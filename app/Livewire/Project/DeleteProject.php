<?php

namespace App\Livewire\Project;

use App\Models\Project;
use Livewire\Component;

class DeleteProject extends Component
{
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
        if ($project->isEmpty()) {
            $project->delete();

            return redirect()->route('project.index');
        }

        return $this->dispatch('error', "<strong>Project {$project->name}</strong> has resources defined, please delete them first.");
    }
}
