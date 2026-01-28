<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ComposeDatabaseBackups extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public ServiceDatabase $composeDatabase;

    public array $parameters;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();

            $project = currentTeam()
                ->projects()
                ->where('uuid', $this->parameters['project_uuid'])
                ->firstOrFail();

            $environment = $project->environments()
                ->where('uuid', $this->parameters['environment_uuid'])
                ->firstOrFail();

            $this->application = $environment->applications()
                ->where('uuid', $this->parameters['application_uuid'])
                ->firstOrFail();

            $this->authorize('view', $this->application);

            $this->composeDatabase = $this->application->composeDatabases()
                ->whereUuid($this->parameters['compose_database_uuid'])
                ->firstOrFail();

            // Check if backups are supported for this database
            if (! $this->composeDatabase->isBackupSolutionAvailable()) {
                return redirect()->route('project.application.database-backups', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);
            }

            // Check if import is supported for this database type
            $dbType = $this->composeDatabase->databaseType();
            $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
            $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.compose-database-backups');
    }
}
