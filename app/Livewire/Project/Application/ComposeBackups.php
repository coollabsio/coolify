<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ComposeBackups extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public $composeDatabases;

    public $selectedDatabase = null;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount(Application $application)
    {
        $this->application = $application;
        $this->authorize('view', $this->application);
        $this->composeDatabases = $this->application->composeDatabases()
            ->get()
            ->filter(fn ($db) => $db->isBackupSolutionAvailable());

        if ($this->composeDatabases->isNotEmpty()) {
            $this->selectedDatabase = $this->composeDatabases->first();
        }
    }

    public function selectDatabase($databaseId)
    {
        $this->selectedDatabase = $this->composeDatabases->firstWhere('id', $databaseId);
    }

    public function render()
    {
        return view('livewire.project.application.compose-backups');
    }
}
