<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DatabaseBackups extends Component
{
    use AuthorizesRequests;

    public ?Application $application = null;

    public ?ServiceDatabase $serviceDatabase = null;

    public array $parameters;

    public array $query;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount(): void
    {
        try {
            $this->parameters = get_route_parameters();
            $this->query = request()->query();
            $this->application = Application::whereUuid($this->parameters['application_uuid'])->first();
            if (! $this->application) {
                redirect()->route('dashboard');

                return;
            }
            $this->authorize('view', $this->application);

            $this->serviceDatabase = $this->application->databases()
                ->whereUuid($this->parameters['compose_db_uuid'] ?? '')
                ->first();
            if (! $this->serviceDatabase) {
                redirect()->route('project.application.configuration', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);

                return;
            }

            if (! $this->serviceDatabase->isBackupSolutionAvailable()) {
                redirect()->route('project.application.configuration', $this->parameters);

                return;
            }

            $dbType = $this->serviceDatabase->databaseType();
            $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
            $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.database-backups');
    }
}
