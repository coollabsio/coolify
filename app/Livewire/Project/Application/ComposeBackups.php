<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ComposeBackups extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public $serviceDatabases;

    public ?ServiceDatabase $selectedDatabase = null;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        $this->loadDatabases();
    }

    public function loadDatabases(): void
    {
        $this->serviceDatabases = $this->application->serviceDatabases()
            ->get()
            ->filter(fn ($db) => $db->isBackupSolutionAvailable());
    }

    public function selectDatabase($databaseId): void
    {
        $this->selectedDatabase = $this->application->serviceDatabases()->find($databaseId);
    }

    public function render()
    {
        return view('livewire.project.application.compose-backups');
    }
}
