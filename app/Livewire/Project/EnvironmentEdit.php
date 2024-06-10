<?php

namespace App\Livewire\Project;

use App\Models\Application;
use App\Models\Project;
use Livewire\Component;

class EnvironmentEdit extends Component
{
    public Project $project;

    public Application $application;

    public $environment;

    public array $parameters;

    protected $rules = [
        'environment.name' => 'required|min:3|max:255',
        'environment.description' => 'nullable|min:3|max:255',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->project = Project::ownedByCurrentTeam()->where('uuid', request()->route('project_uuid'))->first();
        $this->environment = $this->project->environments()->where('name', request()->route('environment_name'))->first();
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->environment->save();

            return redirect()->route('project.environment.edit', ['project_uuid' => $this->project->uuid, 'environment_name' => $this->environment->name]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.environment-edit');
    }
}
