<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Manages scheduled backup configuration for database services within
 * a Docker Compose (GitHub App / dockercompose buildpack) Application deployment.
 *
 * This component mirrors the functionality of the Service-based DatabaseBackups
 * component but targets Application-owned ServiceDatabase records.
 */
class ComposeServiceBackups extends Component
{
    use AuthorizesRequests;

    public ?Application $application = null;

    public ?ServiceDatabase $serviceDatabase = null;

    public array $parameters;

    public array $query;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->query = request()->query();

            $this->application = Application::whereUuid($this->parameters['application_uuid'])->first();
            if (! $this->application || $this->application->build_pack !== 'dockercompose') {
                return redirect()->route('dashboard');
            }
            $this->authorize('view', $this->application);

            $serviceName = $this->parameters['service_name'];
            $this->serviceDatabase = ServiceDatabase::where([
                'name' => $serviceName,
                'application_id' => $this->application->id,
            ])->first();

            if (! $this->serviceDatabase) {
                return redirect()->route('project.application.configuration', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);
            }

            if (! $this->serviceDatabase->isBackupSolutionAvailable()) {
                return redirect()->route('project.application.configuration', $this->parameters);
            }

            // Check if import/restore is supported for this database type
            $dbType = $this->serviceDatabase->databaseType();
            $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
            $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.compose-service-backups');
    }
}
