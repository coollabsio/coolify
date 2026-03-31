<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ComposeDatabaseBackups extends Component
{
    use AuthorizesRequests;

    public ?Application $application = null;

    public ?ServiceDatabase $serviceDatabase = null;

    public array $parameters;

    public bool $isImportSupported = false;

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->application = Application::whereUuid($this->parameters['application_uuid'])->first();
            if (! $this->application) {
                return redirect()->route('dashboard');
            }
            $this->authorize('view', $this->application);

            $this->serviceDatabase = $this->application->databases()->whereUuid($this->parameters['stack_service_uuid'])->first();
            if (! $this->serviceDatabase) {
                return redirect()->route('project.application.configuration', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);
            }

            if (! $this->serviceDatabase->isBackupSolutionAvailable() && ! $this->serviceDatabase->is_migrated) {
                return redirect()->route('project.application.configuration', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);
            }

            $dbType = $this->serviceDatabase->databaseType();
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
