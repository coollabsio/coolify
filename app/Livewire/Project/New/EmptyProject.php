<?php

namespace App\Livewire\Project\New;

use App\Models\Project;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class EmptyProject extends Component
{
    public function createEmptyProject()
    {
        $project = Project::create([
            'name' => generate_random_name(),
            'team_id' => currentTeam()->id,
            'uuid' => (string) new Cuid2,
        ]);

        return redirect()->route('project.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $project->environments->first()->uuid]);
    }
}
