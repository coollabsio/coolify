<?php

namespace App\Livewire\Project\Database\Backup;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;

class Execution extends Component
{
    public $database;

    public ?ScheduledDatabaseBackup $backup;

    public $executions;

    public $s3s;

    public function mount()
    {
        $backup_uuid = request()->route('backup_uuid');
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first()->load(['applications']);
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $database = $environment->databases()->where('uuid', request()->route('database_uuid'))->first();
        if (! $database) {
            return redirect()->route('dashboard');
        }
        $backup = $database->scheduledBackups->where('uuid', $backup_uuid)->first();
        if (! $backup) {
            return redirect()->route('dashboard');
        }
        $executions = collect($backup->executions)->sortByDesc('created_at');
        $this->database = $database;
        $this->backup = $backup;
        $this->executions = $executions;
        $this->s3s = currentTeam()->s3s;
    }

    public function render()
    {
        return view('livewire.project.database.backup.execution');
    }
}
