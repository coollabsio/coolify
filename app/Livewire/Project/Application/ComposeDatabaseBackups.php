<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ComposeDatabaseBackups extends Component
{
    use AuthorizesRequests;

    public Application $application;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        $this->authorize('view', $this->application);
    }

    public function render()
    {
        $databases = $this->application->composeDatabases()
            ->get()
            ->filter(fn ($db) => $db->isBackupSolutionAvailable());

        return view('livewire.project.application.compose-database-backups', [
            'databases' => $databases,
        ]);
    }
}
