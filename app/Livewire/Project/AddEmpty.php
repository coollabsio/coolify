<?php

namespace App\Livewire\Project;

use App\Models\Project;
use Livewire\Attributes\Rule;
use Livewire\Component;

class AddEmpty extends Component
{
    #[Rule(['required', 'string', 'min:3'])]
    public string $name;

    #[Rule(['nullable', 'string'])]
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
