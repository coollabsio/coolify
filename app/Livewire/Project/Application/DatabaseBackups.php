<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Livewire component for managing database backups in Docker Compose Applications.
 * 
 * This component enables backup management for databases detected in Docker Compose
 * files deployed via GitHub App (using the dockercompose buildpack).
 * 
 * @see https://github.com/coollabsio/coolify/issues/7528
 */
class DatabaseBackups extends Component
{
    use AuthorizesRequests;

    public ?Application $application = null;

    public $serviceDatabases = [];

    public ?ServiceDatabase $selectedDatabase = null;

    public array $parameters;

    protected $listeners = ['refreshScheduledBackups' => '$refresh', 'selectDatabase'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->application = Application::whereUuid($this->parameters['application_uuid'])->first();
            
            if (! $this->application) {
                return redirect()->route('dashboard');
            }
            
            $this->authorize('view', $this->application);
            
            // Only Docker Compose applications can have service databases
            if ($this->application->build_pack !== 'dockercompose') {
                return redirect()->route('project.application.configuration', $this->parameters);
            }

            // Load all service databases for this application
            $this->serviceDatabases = $this->application->serviceDatabases()
                ->get()
                ->filter(function ($db) {
                    return $db->isBackupSolutionAvailable();
                });

            if ($this->serviceDatabases->isEmpty()) {
                return redirect()->route('project.application.configuration', $this->parameters);
            }

            // Select first database by default, or from query parameter
            $backupUuid = request()->route('backup_uuid');
            if ($backupUuid) {
                $this->selectedDatabase = $this->serviceDatabases->firstWhere('uuid', $backupUuid);
            }
            
            if (! $this->selectedDatabase) {
                $this->selectedDatabase = $this->serviceDatabases->first();
            }

        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function selectDatabase($uuid)
    {
        $this->selectedDatabase = $this->serviceDatabases->firstWhere('uuid', $uuid);
    }

    public function render()
    {
        return view('livewire.project.application.database-backups');
    }
}
