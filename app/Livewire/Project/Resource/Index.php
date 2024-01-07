<?php

namespace App\Livewire\Project\Resource;

use App\Models\Environment;
use App\Models\Project;
use Livewire\Component;

class Index extends Component
{
    public Project $project;
    public Environment $environment;
    public function mount () {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        $this->project = $project;
        $this->environment = $environment;
    }
    public function render()
    {
        return view('livewire.project.resource.index');
    }
}
