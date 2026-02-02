<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DatabaseBackupDetail extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public ServiceDatabase $serviceDatabase;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        $this->authorize('view', $this->application);

        if (! $this->serviceDatabase->isBackupSolutionAvailable()) {
            return redirect()->route('project.application.database-backups', [
                'project_uuid' => $this->application->environment->project->uuid,
                'environment_uuid' => $this->application->environment->uuid,
                'application_uuid' => $this->application->uuid,
            ]);
        }

        // Check if import is supported for this database type
        $dbType = $this->serviceDatabase->databaseType();
        $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
        $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));
    }

    public function render()
    {
        return view('livewire.project.application.database-backup-detail');
    }
}
