<?php

namespace App\Livewire\Project\Database;

use Livewire\Component;

class Configuration extends Component
{
    public $currentRoute;

    public $database;

    public $project;

    public $environment;

    public function mount()
    {
        $this->currentRoute = request()->route()->getName();

        $project = currentTeam()
            ->projects()
            ->select('id', 'uuid', 'team_id')
            ->where('uuid', request()->route('project_uuid'))
            ->firstOrFail();
        $environment = $project->environments()
            ->select('id', 'name', 'project_id', 'uuid')
            ->where('uuid', request()->route('environment_uuid'))
            ->firstOrFail();
        $database = $environment->databases()
            ->where('uuid', request()->route('database_uuid'))
            ->firstOrFail();

        $this->database = $database;
        $this->project = $project;
        $this->environment = $environment;
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
