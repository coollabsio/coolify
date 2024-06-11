<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use App\Models\Project;
use Livewire\Component;

class AddEnvironment extends Component
{
    public Project $project;

    public string $name = '';

    public string $description = '';

    protected $rules = [
        'name' => 'required|string|min:3',
    ];

    protected $validationAttributes = [
        'name' => 'Environment Name',
    ];

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
        } finally {
            $this->name = '';
        }
    }
}
