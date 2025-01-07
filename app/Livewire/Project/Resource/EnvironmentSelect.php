<?php

namespace App\Livewire\Project\Resource;

use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class EnvironmentSelect extends Component
{
    public Collection $environments;

    public string $project_uuid = '';

    public string $selectedEnvironment = '';

    public function mount()
    {
        $this->selectedEnvironment = request()->route('environment_uuid');
        $this->project_uuid = request()->route('project_uuid');
    }

    public function updatedSelectedEnvironment($value)
    {
        if ($value === 'edit') {
            return redirect()->route('project.show', [
                'project_uuid' => $this->project_uuid,
            ]);
        } else {
            return redirect()->route('project.resource.index', [
                'project_uuid' => $this->project_uuid,
                'environment_uuid' => $value,
            ]);
        }
    }
}
