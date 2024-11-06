<?php

namespace App\Livewire\Project;

use App\Models\Project;
use Livewire\Attributes\Validate;
use Livewire\Component;

class AddEmpty extends Component
{
    #[Validate(['required', 'string', 'min:3'])]
    public string $name;

    #[Validate(['nullable', 'string'])]
    public string $description = '';

    public function submit()
    {
        try {
            $this->validate();
            $project = Project::create([
                'name' => $this->name,
                'description' => $this->description,
                'team_id' => currentTeam()->id,
            ]);

            return redirect()->route('project.show', $project->uuid);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
