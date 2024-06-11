<?php

namespace App\Livewire\Project\Database;

use Livewire\Component;

class Configuration extends Component
{
    public $database;

    public function mount()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $database = $environment->databases()->where('uuid', request()->route('database_uuid'))->first();
        if (! $database) {
            return redirect()->route('dashboard');
        }
        $this->database = $database;
        if (str($this->database->status)->startsWith('running') && is_null($this->database->config_hash)) {
            $this->database->isConfigurationChanged(true);
            $this->dispatch('configurationChanged');
        }
    }

    public function render()
    {
        return view('livewire.project.database.configuration');
    }
}
