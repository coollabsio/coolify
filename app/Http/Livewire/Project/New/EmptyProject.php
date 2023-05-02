<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Project;
use Livewire\Component;

class EmptyProject extends Component
{
    public function createEmptyProject()
    {
        $project = Project::create([
            'name' => generateRandomName(),
            'team_id' => session('currentTeam')->id,
        ]);
        return redirect()->route('project.environments', ['project_uuid' => $project->uuid, 'environment_name' => 'production']);
    }
}
