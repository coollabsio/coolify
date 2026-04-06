<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Backups extends Component
{
    public Application $application;

    public function mount()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications()->where('uuid', request()->route('application_uuid'))->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }
        $this->application = $application;
    }

    public function render()
    {
        return view('livewire.project.application.backups');
    }
}
