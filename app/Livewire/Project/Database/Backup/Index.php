<?php

namespace App\Livewire\Project\Database\Backup;

use Livewire\Component;

class Index extends Component
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
        // No backups
        if (
            $database->getMorphClass() === 'App\Models\StandaloneRedis' ||
            $database->getMorphClass() === 'App\Models\StandaloneKeydb' ||
            $database->getMorphClass() === 'App\Models\StandaloneDragonfly' ||
            $database->getMorphClass() === 'App\Models\StandaloneClickhouse'
        ) {
            return redirect()->route('project.database.configuration', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'database_uuid' => $database->uuid,
            ]);
        }
        $this->database = $database;
    }

    public function render()
    {
        return view('livewire.project.database.backup.index');
    }
}
