<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use App\Models\Project;
use Livewire\Attributes\Rule;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    #[Rule(['required', 'string', 'min:3'])]
    public string $name;

    #[Rule(['nullable', 'string'])]
    public ?string $description = null;

    public function mount(string $project_uuid)
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            $environment = Environment::create([
                'name' => $this->name,
                'project_id' => $this->project->id,
            ]);

            return redirect()->route('project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_name' => $environment->name,
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.show');
    }
}
