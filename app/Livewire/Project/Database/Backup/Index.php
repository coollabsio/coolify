<?php

namespace App\Livewire\Project\Database\Backup;

use Livewire\Component;

class Index extends Component
{
    public $database;
    public $s3s;
    public function mount() {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        $database = $environment->databases()->where('uuid', request()->route('database_uuid'))->first();
        if (!$database) {
            return redirect()->route('dashboard');
        }
        // No backups for redis
        if ($database->getMorphClass() === 'App\Models\StandaloneRedis') {
            return redirect()->route('project.database.configuration', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'database_uuid' => $database->uuid,
            ]);
        }
        $this->database = $database;
        $this->s3s = currentTeam()->s3s;
    }
    public function render()
    {
        return view('livewire.project.database.backup.index');
    }
}
