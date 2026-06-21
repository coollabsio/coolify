<?php

namespace App\Livewire\Project\New;

use App\Models\Project;
use Livewire\Component;

class EmptyProject extends Component
{
    public function createEmptyProject()
    {
        $project = Project::create([
            'name' => generate_random_name(),
            'team_id' => currentTeam()->id,
            'uuid' => new_public_id(),
        ]);

        return redirectRoute($this, 'project.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $project->environments->first()->uuid]);
    }
}
