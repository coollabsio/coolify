<?php

namespace App\Livewire\Project;

use App\Models\Application;
use App\Models\Project;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Rule;
use Livewire\Component;

class EnvironmentEdit extends Component
{
    public Project $project;

    public Application $application;

    #[Locked]
    public $environment;

    #[Rule(['required', 'string', 'min:3', 'max:255'])]
    public string $name;

    #[Rule(['nullable', 'string', 'max:255'])]
    public ?string $description = null;

    public function mount(string $project_uuid, string $environment_name)
    {
        try {
            $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
            $this->environment = $this->project->environments()->where('name', $environment_name)->firstOrFail();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->environment->update([
                'name' => $this->name,
                'description' => $this->description,
            ]);
        } else {
            $this->name = $this->environment->name;
            $this->description = $this->environment->description;
        }
    }

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->redirectRoute('project.environment.edit', ['environment_name' => $this->environment->name, 'project_uuid' => $this->project->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.environment-edit');
    }
}
