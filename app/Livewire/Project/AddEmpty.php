<?php

namespace App\Livewire\Project;

use App\Models\Project;
use Livewire\Component;

class AddEmpty extends Component
{
    public string $name = '';

    public string $description = '';

    protected $rules = [
        'name' => 'required|string|min:3',
        'description' => 'nullable|string',
    ];

    protected $validationAttributes = [
        'name' => 'Project Name',
        'description' => 'Project Description',
    ];

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
        } finally {
            $this->name = '';
        }
    }
}
