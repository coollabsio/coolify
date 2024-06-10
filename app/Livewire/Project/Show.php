<?php

namespace App\Livewire\Project;

use App\Models\Project;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    public function mount()
    {
        $projectUuid = request()->route('project_uuid');
        $teamId = currentTeam()->id;

        $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $project->load(['environments']);
        $this->project = $project;
    }

    public function render()
    {
        return view('livewire.project.show');
    }
}
