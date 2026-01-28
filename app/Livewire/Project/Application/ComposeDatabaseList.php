<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class ComposeDatabaseList extends Component
{
    public Application $application;

    public $composeDatabases;

    public function mount()
    {
        $this->composeDatabases = $this->application->composeDatabases()
            ->get()
            ->filter(fn ($db) => $db->isBackupSolutionAvailable());
    }

    public function render()
    {
        return view('livewire.project.application.compose-database-list');
    }
}
