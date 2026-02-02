<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DatabaseBackups extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public function mount()
    {
        $this->authorize('view', $this->application);
    }

    public function render()
    {
        return view('livewire.project.application.database-backups', [
            'databases' => $this->application->databases()->get(),
        ]);
    }
}
