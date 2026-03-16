<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DatabaseBackups extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public $companionDatabases = [];

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->authorize('view', $this->application);
            $this->loadCompanionDatabases();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadCompanionDatabases()
    {
        $companion = $this->application->companionService;
        if ($companion) {
            $this->companionDatabases = $companion->databases()->get();
        } else {
            $this->companionDatabases = collect([]);
        }
    }

    public function render()
    {
        return view('livewire.project.application.database-backups');
    }
}
