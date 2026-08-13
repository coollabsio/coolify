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
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            abort(404);
        }
        $environment->load(['applications']);
        $database = $environment->databases()->where('uuid', request()->route('database_uuid'))->first();
        if (! $database) {
            return redirect()->route('dashboard');
        }
        if (! $database->isBackupSolutionAvailable()) {
            return redirect()->route('project.database.configuration', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'database_uuid' => $database->uuid,
            ]);
        }
        $this->database = $database->load([
            'scheduledBackups' => fn ($query) => $query->withCount('executions'),
        ]);
    }

    public function render()
    {
        return view('livewire.project.database.backup.index');
    }
}
